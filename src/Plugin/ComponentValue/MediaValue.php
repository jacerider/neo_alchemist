<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\neo\Helpers\NestedArray;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'media',
  label: new TranslatableMarkup('Media'),
  description: new TranslatableMarkup('Provide media entity values.'),
  group: 'providers',
  weight: 10,
)]
final class MediaValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The supported media types.
   *
   * @var \Drupal\media\MediaTypeInterface[]
   */
  protected array $mediaTypes;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'default' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return;
    }
    $mediaTypes = $shape->getSupportedMediaTypes();
    $shape->setFieldType('entity_reference');
    $shape->setFieldStorageSettings([
      'target_type' => 'media',
    ]);
    $shape->setFieldInstanceSettings([
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => array_combine($mediaTypes, $mediaTypes),
      ],
    ]);
    $shape->setWidget('media_library_widget');
    $shape->getOptionDefault()->alwaysShowForm(TRUE, 'Media always allows default value.');
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return $form;
    }

    foreach ($this->getMediaTypes() as $mediaType) {
      $source = $mediaType->getSource();
      $sourceId = $source->getPluginId();
      switch ($sourceId) {
        case 'image':
          $component = $shape->getComponent();
          $form['default'][$sourceId] = [
            '#type' => 'neo_config_file',
            '#title' => $mediaType->label(),
            '#filename' => Html::getClass($shape->getComponent()->id() . '-' . $shape->id()),
            '#extensions' => ['png'],
            '#dependencies' => [
              $component->getConfigDependencyKey() => [
                $component->getConfigDependencyName(),
              ],
            ],
            '#default_value' => $this->configuration['default'][$sourceId] ?? NULL,
          ];
          break;
      }
    }

    return $form;
  }

  /**
   * Get the supported media types.
   *
   * @return \Drupal\media\MediaTypeInterface[]
   *   The supported media types.
   */
  protected function getMediaTypes(): array {
    if (!isset($this->mediaTypes)) {
      $this->mediaTypes = [];
      $shape = $this->shape;
      if ($shape instanceof ComponentShapeMediaPluginInterface) {
        $this->mediaTypes = $this->entityTypeManager->getStorage('media_type')->loadMultiple($shape->getSupportedMediaTypes());
      }
    }
    return $this->mediaTypes;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
    $preview = NULL;
    $shape = $this->shape;
    if ($this->shape->getOptionDefault()->isEnabled()) {
      if ($shape instanceof ComponentShapeMediaPluginInterface) {
        $previewBuild = $shape->getDefaultPreview();
        if ($previewBuild) {
          $preview['default'] = $previewBuild + [
            '#weight' => -10,
          ];
        }
      }
      if ($this->shape->getDefaultValue()) {
        $defaultMessage = $this->t('Using the default @label.', [
          '@label' => strtolower($shape->getTitle()),
        ]);
      }
      else {
        $defaultMessage = $this->t('No @label will be shown.', [
          '@label' => strtolower($shape->getTitle()),
        ]);
      }
      $preview['empty_selection'] = [
        '#markup' => '<div class="description">' . $defaultMessage . '</div>',
      ];
    }
    if (!empty($element['widget'])) {
      $element['#title'] = $element['widget']['widget']['#title'];
      if (isset($element['widget']['widget']['open_button'])) {
        $element['widget']['widget']['open_button']['#attributes']['class'][] = 'btn-xs';
      }
      if ($element['#type'] === 'fieldset') {
        $element['widget']['widget']['#title_display'] = 'invisible';
      }
      if (!empty($element['widget']['widget']['#required']) && !$form_state->isRebuilding()) {
        $element['widget']['widget']['#element_validate'] = array_filter($element['widget']['widget']['#element_validate'], function ($callback) {
          if (is_array($callback) && $callback[1] === 'validateRequired') {
            return FALSE;
          }
          return TRUE;
        });
      }
      if (empty($element['widget']['widget']['selection'][0]['target_id']['#value'])) {
        $element['widget']['widget']['preview'] = $preview;
      }
      else {
        if ($shape instanceof ComponentShapeMediaPluginInterface) {
          // Support the ability to override media values.
          $mediaId = $element['widget']['widget']['selection'][0]['target_id']['#value'];
          $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
          $overrideForm = [
            '#type' => 'container',
            '#parents' => array_merge($element['widget']['widget']['#parents'], ['media_override']),
          ];
          $overrideForm = $shape->buildMediaOverrideForm($overrideForm, $form_state, $media);
          if (count(Element::children($overrideForm))) {
            $element['widget']['widget']['override'] = $overrideForm + [
              '#weight' => 10,
            ];
          }
        }
      }
    }
    else {
      $element['preview'] = $preview;
      // Allow adding media directly.
      $element['override'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add media'),
        '#name' => str_replace('-', '_', $element['#id']) . '_override',
        '#neo_override' => TRUE,
        '#attributes' => [
          'class' => ['btn-xs mt-2'],
        ],
        '#submit' => [
          [get_class($this), 'submitOverride'],
        ],
        '#ajax' => [
          'callback' => [$this, 'ajaxOverride'],
          'wrapper' => $element['#id'],
        ],
      ];
    }
  }

  /**
   * Submit handler for ajax override.
   */
  public static function submitOverride(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for override.
   */
  public function ajaxOverride(array $form, FormStateInterface $form_state) {
    $shape = $this->shape;
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    $element = NestedArray::getValue($form, $parents);

    if (!empty(Element::getVisibleChildren($element['widget']['widget']['selection']))) {
      // If there is a media item selected, just return the element.
      return NestedArray::getValue($form, $parents);
    }

    $widgetForm = $element['widget']['widget'];
    $widget = $shape->getWidget();
    $widgetFormState = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $widgetOpenButton = $widgetForm['open_button'];
    $widgetFormState->setTriggeringElement($widgetOpenButton);
    $widgetClass = get_class($widget);

    /** @var \Drupal\Core\Ajax\AjaxResponse $response */
    $response = $widgetClass::openMediaLibrary($widgetForm, $widgetFormState);
    $response->addCommand(new ReplaceCommand('#' . $element['#id'], $element));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function massageValuesAlter(array &$values, array $submitted_values, array $original_values, array $form, FormStateInterface $form_state): void {
    $shape = $this->shape;

    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#neo_override'] ?? FALSE) {
      // Set default to disabled when overriding.
      $options = $shape->getOptions();
      $options['default'] = 0;
      $this->getShape()->setOptions($options);
    }
    if ($shape->getScope() === 'config') {
      if (empty($values)) {
        $values = NULL;
      }
      // Remove the value if the user has removed the item so that the widget
      // will be switched back to default. This only needs to happen when in
      // config scope as default option is always toggleable.
      $trigger = $form_state->getTriggeringElement();
      if (isset($trigger['#media_id'])) {
        foreach ($trigger['#submit'] ?? [] as $submit) {
          if (is_array($submit) && $submit[1] === 'removeItem') {
            $values = NULL;
          }
        }
      }
    }
    if ($shape instanceof ComponentShapeMediaPluginInterface) {
      if (!empty($submitted_values[$this->shape->getName()])) {
        $widget_values = $submitted_values[$this->shape->getName()];
        if (isset($widget_values['media_override']) && is_array($widget_values['media_override'])) {
          $shape->massageMediaOverrideValues($values, $widget_values['media_override'], $original_values, $form, $form_state);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onRemove(): void {
    foreach (array_filter($this->configuration['default']) as $type => $default) {
      /** @var \Drupal\neo_config_file\ConfigFileInterface $configFile */
      $configFile = $this->entityTypeManager->getStorage('neo_config_file')->load($default);
      if ($configFile) {
        $configFile->delete();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return $value;
    }
    $media = NULL;
    foreach (array_filter($this->configuration['default']) as $type => $default) {
      /** @var \Drupal\neo_config_file\ConfigFileInterface $configFile */
      $configFile = $this->entityTypeManager->getStorage('neo_config_file')->load($default);
      if ($configFile) {
        // Set media to first found value.
        $file = $configFile->getFile();
        /** @var \Drupal\media\MediaInterface $media */
        $media = $this->entityTypeManager->getStorage('media')->create([
          'bundle' => $type,
        ]);
        /** @var \Drupal\Core\Field\FieldDefinitionInterface $field */
        $field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
        $media->get($field->getName())->setValue($file);
        break;
      }
    }
    if ($media instanceof MediaInterface) {
      $shape->addCacheableDependency($media);
      if ($mediaValue = $shape->getValueFromMedia($media)) {
        $this->stopFurtherProcessing();
        return $mediaValue;
      }
    }
    if (!$shape->getOptionDefault()->isEnabled()) {
      // When default is not enabled and we have not found media, return an
      // empty array so that the image will not be shown.
      return [];
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   *
   * We use alterValue so that the image is always replaced with the
   * media value.
   */
  public function alterValue(mixed $value, string $type): mixed {
    $title = $value['title'] ?? NULL;
    $shape = $this->shape;
    if ($shape instanceof ComponentShapeMediaPluginInterface) {
      $media = $shape->getFieldItem()->entity;
      if ($media instanceof MediaInterface) {
        $value = $shape->getValueFromMedia($media) + $value;
      }
    }
    if ($title !== NULL) {
      $value['title'] = $title;
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return $shape instanceof ComponentShapeMediaPluginInterface;
  }

}
