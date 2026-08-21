<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldLabelOptionsTrait;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Match\FieldFormatterTrait;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity_field',
  label: new TranslatableMarkup('Entity Field Display'),
  description: new TranslatableMarkup('Render a entity field.'),
)]
final class EntityFieldSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use FieldLabelOptionsTrait;
  use FieldFormatterTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
    FormatterPluginManager $formatter_manager,
    ModuleHandlerInterface $module_handler,
    MatcherReference $matcher_reference,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldFormatterManager = $formatter_manager;
    $this->moduleHandler = $module_handler;
    $this->matcherReference = $matcher_reference;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('module_handler'),
      $container->get('neo_alchemist.matcher_reference')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity' => '',
      'field' => '',
    ] + $this->formatterDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if ($entityId = $this->configuration['entity']) {
      $references = $this->matcherReference->getReferences($this->component->getTargetEntityTypeId(), $this->component->getTargetEntityBundle());
      if (isset($references[$entityId])) {
        $summary[] = $this->t('Target Entity: @group → @title', [
          '@group' => $references[$entityId]['group'],
          '@title' => $references[$entityId]['title'],
        ]);
      }
    }
    if ($fieldName = $this->configuration['field']) {
      $field = $this->getField($fieldName);
      if ($field) {
        $summary = array_merge($summary, $this->formatterSettingsSummary($field, $this->configuration));
      }
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $fields = $this->getFields();

    $options = $this->matcherReference->getReferencesAsOptions($this->component->getTargetEntityTypeId(), $this->component->getTargetEntityBundle());
    $form['entity'] = [
      '#type' => 'select',
      '#title' => $this->t('Target entity type'),
      '#options' => $options,
      '#default_value' => $this->configuration['entity'],
      '#empty_option' => $this->component->getTargetEntity()->getEntityType()->getLabel(),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    $options = array_map(fn ($field) => $field->getLabel(), $fields);

    $fieldName = $this->configuration['field'];
    $form['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#options' => $options,
      '#default_value' => $fieldName,
      '#empty_option' => $this->t('- None -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($fieldName && isset($fields[$fieldName])) {
      $field = $fields[$fieldName];
      $form = $this->formatterConfigurationForm($form, $form_state, $field, $this->configuration, [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ]);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    if ($fieldName = $this->configuration['field']) {
      $entity = $this->component->getTargetEntity();
      if ($entityKey = $this->configuration['entity']) {
        $entity = $this->matcherReference->getReferenceEntity($this->component->getTargetEntity(), $entityKey, FALSE, $this->getCacheableMetadata()) ?? $entity;
      }
      if ($entity instanceof ContentEntityInterface && $entity->hasField($fieldName) && !$entity->get($fieldName)->isEmpty()) {
        return $entity->get($fieldName)->view([
          'label' => $this->configuration['field_label'],
          'type' => $this->configuration['field_plugin'],
          'settings' => $this->configuration['field_settings'],
          'third_party_settings' => $this->configuration['field_third_party_settings'],
        ]);
      }
    }
    return [];
  }

  /**
   * Get all fields for the current entity type.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   The field definitions.
   */
  private function getFields(): array {
    $fields = [];
    $entityTypeId = $this->component->getTargetEntityTypeId();
    if ($entityKey = $this->configuration['entity']) {
      $entity = $this->matcherReference->getReferenceEntity($this->component->getTargetEntity(), $entityKey, TRUE);
      $entityTypeId = $entity->getEntityTypeId();
    }
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    foreach ($bundles as $bundle => $data) {
      $fields += $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    }
    return $fields;
  }

  /**
   * Get field definition.
   *
   * @param string $fieldName
   *   The field name.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition.
   */
  private function getField(string $fieldName): ?FieldDefinitionInterface {
    return $this->getFields()[$fieldName] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return !empty($component->getTargetEntityTypeId());
  }

}
