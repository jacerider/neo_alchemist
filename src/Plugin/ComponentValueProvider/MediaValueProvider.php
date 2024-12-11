<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'media',
  label: new TranslatableMarkup('Media'),
  description: new TranslatableMarkup('Provide media entity values.'),
  ref_types: [
    'image',
  ],
  weight: 10,
)]
final class MediaValueProvider extends ComponentValueProviderPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
      // 'default' => $this->shape->getDefaultValue(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Only allow processing if the entity is not new.
   */
  public function allowProcessing(string $op): bool {
    if ($this->shape->getScope() === 'config') {
      // Do not alter on config.
      // return FALSE;
    }
    return parent::allowProcessing($op);
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    $this->shape->setFieldType('entity_reference');
    $this->shape->setFieldStorageSettings([
      'target_type' => 'media',
    ]);
    $this->shape->setFieldInstanceSettings([
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => [
          'image' => 'image',
        ],
      ],
    ]);
    $this->shape->setWidget('media_library_widget');
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function providerForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    // $this->shape->setFieldItemValue($this->configuration['default']);
    // $form = $this->shape->getForm($form, $form_state);
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function providerValidate(array $form, FormStateInterface $form_state): void {
    // $values = $form_state->getValues()[$this->shape->getName()] ?? [];
    // $this->shape->validateForm($form, $form_state, $values);
  }

  /**
   * Form submit for the value provider plugin configuration.
   */
  protected function providerSubmit(array $form, FormStateInterface $form_state): void {
    // $values = $form_state->getValues()[$this->shape->getName()] ?? [];
    // $value = $this->shape->massageFormValues($form, $form_state, $values);
    // $form_state->setValues(['default' => $value]);
  }

  // /**
  //  * {@inheritdoc}
  //  */
  // public function formAlter(array &$element, FormStateInterface $form_state) {
  //   // When an image provides a default value, we need to provide a way to
  //   // toggle it on/off.
  //   $selectionElement = $element['widget']['selection'] ?? [];
  //   $element['widget']['hide'] = [
  //     '#type' => 'checkbox',
  //     '#title' => $this->t('Hide Default Image'),
  //     '#default_value' => empty($this->shape->getValue()),
  //     '#access' => empty(Element::children($selectionElement)) && !empty($this->shape->getDefaultValue()),
  //   ];
  // }

  // /**
  //  * {@inheritdoc}
  //  */
  // public function formValuesAlter(array &$values, array $original) {
  //   $hide = !empty($original['hide']);
  //   if (!$hide) {
  //     $values += $this->shape->getDefaultValue();
  //   }
  //   if (!empty($values['target_id'])) {
  //     // If the target ID is set, we remove all other values.
  //     $values = [
  //       'target_id' => $values['target_id'],
  //     ];
  //   }
  // }

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value): mixed {
    $entity = $this->shape->getFieldItem()->entity;
    if ($entity instanceof MediaInterface) {
      $source = $entity->getSource();
      $fid = $source->getSourceFieldValue($entity);
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file instanceof FileInterface) {
        $value = [
          'src' => $file->createFileUrl(),
          'alt' => $source->getMetadata($entity, 'thumbnail_alt_value'),
          'width' => $source->getMetadata($entity, 'width'),
          'height' => $source->getMetadata($entity, 'height'),
          'target_id' => $entity->id(),
        ];
        $this->stopFurtherProcessing();
      }
    }
    elseif ($entity instanceof FileInterface) {
      $value = [
        'src' => $entity->createFileUrl(),
        'alt' => $entity->get('alt')->value,
        'width' => $entity->get('width')->value,
        'height' => $entity->get('height')->value,
        'target_id' => $entity->id(),
      ];
      $this->stopFurtherProcessing();
    }
    return $value;
  }

}
