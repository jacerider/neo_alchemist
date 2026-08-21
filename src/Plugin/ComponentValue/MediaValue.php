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
use Drupal\neo_alchemist\Shape\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'media',
  label: new TranslatableMarkup('Media'),
  description: new TranslatableMarkup('Provides the media picker and renders the picked media. Optionally supplies a fallback image. Does not block other providers.'),
  group: 'providers',
  weight: 10,
  // Removing this plugin destroys the prop's authoring UI — onShapeInit() is
  // what makes the prop a media reference field with the media-library
  // widget — so it is locked into the provider list.
  status_lock: TRUE,
)]
final class MediaValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The file extensions allowed for config-hosted (neo_config_file) images.
   */
  protected const CONFIG_FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

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
   *
   * Never claim by default. Under stop_when_found this provider claimed the
   * THREADED seeded value (the schema example, or the configured default) —
   * it is non-empty, so the claim fired even though this plugin contributed
   * nothing — and every provider a site builder placed after it silently
   * never ran, an ordering trap that had real configs fighting it with
   * hand-set modes. For a media-only provider list the two modes are
   * outcome-identical (nothing follows that could overwrite), and this
   * plugin's real jobs — the widget (onShapeInit), the media-to-image
   * conversion (alterValue) and the fallback image (provideDefaultValue) —
   * are all mode-independent.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_CONTINUE;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [$this->t('Media picker & value converter')];
    if (array_filter($this->configuration['default'])) {
      $summary[] = $this->t('Fallback image configured');
    }
    return $summary;
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
            '#extensions' => static::CONFIG_FILE_EXTENSIONS,
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
    if ($shape instanceof ComponentShapeMediaPluginInterface && $shape->getScope() === 'field' && $this->getImageMediaType()) {
      // Config-hosted component values (field default layouts and Alchemist
      // blocks) must travel through config sync, so media entities cannot be
      // referenced. Replace the media library widget with a neo_config_file
      // upload, which stores the file itself in config.
      unset($element['widget']);
      // Mirror the media widget: while the default is in use, show the preview
      // plus an override button (instead of the upload) whose AJAX wrapper is
      // the whole shape. Clicking it turns the default off (via #neo_override,
      // see ::massageValuesAlter()) and re-renders the shape, revealing the
      // upload — so choosing a custom image automatically unchecks "Default".
      if ($this->shape->getOptionDefault()->isEnabled()) {
        if ($preview) {
          $element['preview'] = $preview;
        }
        $element['override'] = [
          '#type' => 'submit',
          '#value' => $this->t('Upload @label', ['@label' => strtolower($shape->getTitle())]),
          '#name' => str_replace('-', '_', $element['#id']) . '_override',
          // Names the shape this button belongs to, because
          // ::massageValuesAlter() runs for every media prop on the component
          // and the triggering element is form-global. A bare TRUE there means
          // one click turns the default off for all of them.
          '#neo_override' => $shape->id(),
          '#attributes' => [
            'class' => ['btn-xs mt-2'],
          ],
          // Revealing the upload is low-risk and has nothing to do with the
          // rest of the form. Without this, a validation error anywhere skips
          // both the submit handlers and the rebuild — each is gated on
          // !FormState::hasAnyErrors() in FormBuilder — while still raising
          // FormAjaxException, so the callback below is handed the original,
          // un-rebuilt form in which the default is still on and this button is
          // still here. Core's own media library open button carries the same
          // property for the same reason.
          // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
          '#limit_validation_errors' => [],
          '#submit' => [
            [get_class($this), 'submitOverride'],
          ],
          '#ajax' => [
            'callback' => [$this, 'ajaxConfigFileOverride'],
            'wrapper' => $element['#id'],
          ],
        ];
        return;
      }
      $component = $shape->getComponent();
      $fieldDefinition = $component->getFieldItem()->getFieldDefinition();
      // The filename must be STABLE across form rebuilds. The component
      // instance UUID is regenerated on every build of the add form, so keying
      // the filename on it renames the upload each rebuild and spawns a new
      // neo_config_file per name. Derive it instead from the host field, the
      // component type and the shape — stable across rebuilds and add→edit, and
      // unique per (field/block, component, prop).
      $filenameSeed = implode('-', [
        $fieldDefinition->getConfigDependencyName(),
        $component->id(),
        $shape->id(),
      ]);
      $element['config_file'] = [
        '#type' => 'neo_config_file',
        '#title' => $shape->getTitle(),
        '#filename' => Html::getClass($filenameSeed),
        '#extensions' => static::CONFIG_FILE_EXTENSIONS,
        '#dependencies' => [
          $fieldDefinition->getConfigDependencyKey() => [
            $fieldDefinition->getConfigDependencyName(),
          ],
        ],
        '#default_value' => $shape->getFieldItemValue()['config_file'] ?? NULL,
      ];
      return;
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
        // Names the shape this button belongs to. @see the branch above.
        '#neo_override' => $shape->id(),
        '#attributes' => [
          'class' => ['btn-xs mt-2'],
        ],
        // Revealing the widget is low-risk and has nothing to do with the rest
        // of the form. Without this, a validation error anywhere skips both the
        // submit handlers and the rebuild — each is gated on
        // !FormState::hasAnyErrors() in FormBuilder — while still raising
        // FormAjaxException, so ::ajaxOverride() is handed the original,
        // un-rebuilt form, which has no widget in it at all. Core's own media
        // library open button carries the same property for the same reason.
        // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
        '#limit_validation_errors' => [],
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
   * Ajax callback for the config-file override button.
   *
   * Re-renders the whole shape so that, with the default now turned off, the
   * neo_config_file upload replaces the override button.
   */
  public function ajaxConfigFileOverride(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Ajax callback for override.
   */
  public function ajaxOverride(array $form, FormStateInterface $form_state) {
    $shape = $this->shape;
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    $element = NestedArray::getValue($form, $parents);

    // The rebuild is what turns the "default" option off and so puts the widget
    // into the form; this callback exists to reach into it. If it is not there
    // — a validation error that #limit_validation_errors cannot suppress still
    // skips the rebuild — hand the element back unchanged rather than
    // dereferencing a widget that was never built. ::ajaxConfigFileOverride()
    // does only this on every path.
    $selection = $element['widget']['widget']['selection'] ?? NULL;
    if (!is_array($selection) || !empty(Element::getVisibleChildren($selection))) {
      // No widget to drive, or a media item is already selected.
      return $element;
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

    if ($shape instanceof ComponentShapeMediaPluginInterface && $shape->getScope() === 'field' && $this->getImageMediaType()) {
      // Config-hosted values are stored as neo_config_file references.
      // @see ::formAlter()
      $trigger = $form_state->getTriggeringElement();
      $configFileId = $submitted_values['config_file'] ?? NULL;
      // Turn the "Default" option off when the user switches to a custom image,
      // either by clicking this shape's own override button (#neo_override
      // names the shape, because the triggering element is form-global and this
      // method runs for every media prop on the component) or by uploading a
      // file — mirroring the media widget so the custom image is shown.
      if (($trigger['#neo_override'] ?? NULL) === $shape->id() || $configFileId) {
        $options = $shape->getOptions();
        if (!empty($options['default'])) {
          $options['default'] = 0;
          $shape->setOptions($options);
        }
      }
      $values = $configFileId ? ['config_file' => $configFileId] : NULL;
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    // Only for the shape whose own override button was clicked: this method
    // runs once per media prop on the component and the triggering element is
    // form-global, so a bare truthiness test turned the default off for every
    // media prop at once — visibly, since the others then render nothing.
    if (($trigger['#neo_override'] ?? NULL) === $shape->id()) {
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
      //
      // Only for this shape's own Remove button: the triggering element is
      // form-global and this method runs for every media prop on the component,
      // so an unscoped test empties them all. The media widget's buttons carry
      // no shape identity of their own, hence the array-parents placement.
      //
      // Note this drops the value but leaves the "default" option off, so the
      // prop renders nothing until the option is turned back on. Restoring the
      // option here does not work: the widget would not be rebuilt, and core's
      // remove button AJAX callback returns that widget element, so it fatals
      // on the missing #parents.
      $trigger = $form_state->getTriggeringElement();
      if (isset($trigger['#media_id']) && $this->triggerBelongsToShape($trigger, $form)) {
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
   * Whether a triggering element sits inside a shape's own form element.
   *
   * ::massageValuesAlter() runs once for every media prop on the component,
   * but the triggering element is form-global, so a button has to be located
   * before its effect is applied. The media widget's own buttons carry no
   * shape identity — unlike the override button, which this module builds and
   * can stamp — so they are placed by array parents instead.
   *
   * @param array $trigger
   *   The triggering element.
   * @param array $form
   *   The shape's own form element.
   *
   * @return bool
   *   TRUE when the trigger is this shape's, FALSE when it is not or when
   *   either element cannot be placed.
   */
  private function triggerBelongsToShape(array $trigger, array $form): bool {
    $shapeParents = $form['#array_parents'] ?? NULL;
    $triggerParents = $trigger['#array_parents'] ?? NULL;
    if (empty($shapeParents) || empty($triggerParents)) {
      // Unplaceable: claim nothing rather than acting on every media prop.
      return FALSE;
    }
    return array_slice($triggerParents, 0, count($shapeParents)) === $shapeParents;
  }

  /**
   * {@inheritdoc}
   */
  public function onRemove(): void {
    foreach (array_filter($this->configuration['default']) as $default) {
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
      // Take the first configured fallback that actually resolves to a file.
      $media = $this->mediaFromConfigFile($default, $type);
      if ($media) {
        break;
      }
    }
    if ($media instanceof MediaInterface) {
      if ($mediaValue = $shape->getValueFromMedia($media)) {
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
      if (is_string($value)) {
        $value = [
          'target_id' => $value,
        ];
      }
      if (!empty($value['config_file']) && is_string($value['config_file'])) {
        // Config-hosted values reference a neo_config_file instead of a media
        // entity; hydrate from the stored file.
        if ($mediaValue = $this->getValueFromConfigFile($value['config_file'])) {
          $value = $mediaValue + $value;
        }
      }
      else {
        $media = $shape->getFieldItem()->entity;
        if ($media instanceof MediaInterface) {
          $value = $shape->getValueFromMedia($media) + $value;
        }
      }
    }
    if ($title !== NULL) {
      $value['title'] = $title;
    }
    return $value;
  }

  /**
   * Builds a media value from a neo_config_file entity.
   *
   * Wraps the stored file in an unsaved media entity so that the shape's
   * getValueFromMedia() can produce the same value structure as a real media
   * reference would.
   *
   * @param string $configFileId
   *   The neo_config_file entity ID.
   *
   * @return array
   *   The media value, or an empty array if the file could not be resolved.
   */
  protected function getValueFromConfigFile(string $configFileId): array {
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return [];
    }
    $mediaType = $this->getImageMediaType();
    if (!$mediaType) {
      return [];
    }
    $media = $this->mediaFromConfigFile($configFileId, $mediaType->id());
    if (!$media) {
      return [];
    }
    return $shape->getValueFromMedia($media);
  }

  /**
   * Builds an unsaved media entity wrapping a config file's file.
   *
   * The fallback media never exists in storage: it is assembled so the shape
   * can read a value out of it. Cacheability therefore has to hang off the
   * config file, which is the thing an editor can actually change — an unsaved
   * entity carries no usable cache tags, so depending on it would leave render
   * caches holding a replaced fallback image.
   *
   * @param string $configFileId
   *   The neo_config_file ID holding the fallback.
   * @param string $bundle
   *   The media bundle to build.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The unsaved media entity, or NULL if the config file or its file is gone.
   */
  private function mediaFromConfigFile(string $configFileId, string $bundle): ?MediaInterface {
    /** @var \Drupal\neo_config_file\ConfigFileInterface|null $configFile */
    $configFile = $this->entityTypeManager->getStorage('neo_config_file')->load($configFileId);
    if (!$configFile) {
      return NULL;
    }
    $file = $configFile->getFile();
    if (!$file) {
      return NULL;
    }
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
    ]);
    /** @var \Drupal\Core\Field\FieldDefinitionInterface $field */
    $field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
    $media->get($field->getName())->setValue($file);
    $this->shape->addCacheableDependency($configFile);
    return $media;
  }

  /**
   * Gets the first supported media type that uses the image source.
   *
   * @return \Drupal\media\MediaTypeInterface|null
   *   The image media type, or NULL if none of the supported types use the
   *   image source.
   */
  protected function getImageMediaType() {
    foreach ($this->getMediaTypes() as $mediaType) {
      if ($mediaType->getSource()->getPluginId() === 'image') {
        return $mediaType;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return $shape instanceof ComponentShapeMediaPluginInterface;
  }

}
