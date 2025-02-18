<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
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
    EntityTypeManagerInterface $entity_type_manager
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
            '#filename' => Html::getClass($shape->getComponent()->id() . '-' . $shape->getNestedId()),
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
    if ($this->shape->getOptionDefault()->isEnabled()) {
      $value = $this->shape->getValue();
      if (!empty($value['src'])) {
        $element['preview']['default'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="media-library-item--preview"><img src="{{ src }}" alt="{{ alt }}" width="{{ width }}" height="{{ height }}" /></div>',
          '#context' => $value,
          '#weight' => -10,
        ];
        $element['preview']['empty_selection'] = [
          '#markup' => '<div class="description">' . $this->t('Using the default image.') . '</div>',
        ];
      }
    }
    if (!empty($element['widget'])) {
      $element['#title'] = $element['widget']['widget']['#title'];
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
      if ($mediaValue = $shape->getValueFromMedia($media)) {
        $this->stopFurtherProcessing();
        return $mediaValue;
      }
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyOverrideValue(mixed $value): mixed {
    $shape = $this->shape;
    if ($shape instanceof ComponentShapeMediaPluginInterface) {
      $media = $shape->getFieldItem()->entity;
      if ($media instanceof MediaInterface) {
        if ($mediaValue = $shape->getValueFromMedia($media)) {
          $shape->getOptionDefault()->setValue(FALSE, 'Show custom value as media found.');
          $value = $mediaValue;
        }
      }
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
