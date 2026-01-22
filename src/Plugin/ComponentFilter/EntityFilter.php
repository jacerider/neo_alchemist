<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentFilter;
use Drupal\neo_alchemist\ComponentFilterInterface;
use Drupal\neo_alchemist\ComponentFilterPluginBase;
use Drupal\neo_alchemist\ComponentFilterPluginEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_filter.
 */
#[ComponentFilter(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('An entity filter.'),
)]
final class EntityFilter extends ComponentFilterPluginBase implements ContainerFactoryPluginInterface, ComponentFilterPluginEntityInterface {

  use DependencySerializationTrait;

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
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentFilterInterface $filter,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $filter, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['filter'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field_type' => 'autocomplete',
      'multiple' => FALSE,
      'multiple_operator' => '+',
      'entity_type' => '',
      'bundles' => [],
      'include' => [],
      'exclude' => [],
      'entity_preview' => NULL,
    ];
  }

  /**
   * Get multiple operators.
   *
   * @return array
   *   The multiple operators.
   */
  protected function multipleOperators(): array {
    return [
      ',' => $this->t('AND'),
      '+' => $this->t('OR'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Field type: %type', ['%type' => ucfirst($this->configuration['field_type'])]);
    $summary[] = $this->t('Allow multiple values: %multiple', ['%multiple' => $this->configuration['multiple'] ? $this->t('Yes') : $this->t('No')]);
    if ($entityTypeId = $this->configuration['entity_type']) {
      if ($entityType = $this->entityTypeManager->getDefinition($entityTypeId)) {
        $summary[] = $this->t('Entity type: %label', ['%label' => $entityType->getLabel()]);
      }
      if ($bundleIds = $this->configuration['bundles']) {
        $bundles = array_map(fn($bundle) => $bundle['label'], array_intersect_key($this->entityTypeBundleInfo->getBundleInfo($entityTypeId), array_flip($bundleIds)));
        $summary[] = $this->t('Entity bundles: %bundles', ['%bundles' => implode(', ', $bundles)]);
      }
      if ($defaultValue = $this->filter->getDefaultValue()) {
        if ($entity = $this->entityTypeManager->getStorage($entityTypeId)->load($defaultValue)) {
          $summary[] = $this->t('Default value: %value', ['%value' => $entity->label()]);
        }
      }
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function valueSummary(?string $value): ?string {
    if ($this->configuration['entity_type'] && $value) {
      $values = explode($this->configuration['multiple_operator'], $value);

      if ($this->configuration['multiple']) {
        return (string) count($values);
      }
      else {
        $value = reset($values);
        $entities = [$this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($value)];
        $entities = array_filter($entities);
        if ($entities) {
          $summary = implode(', ', array_map(fn($entity) => $entity->label(), $entities));
          return substr($summary, 0, 40) . (strlen($summary) > 40 ? '...' : '');
        }
      }
    }
    return NULL;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['field_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Field type'),
      '#options' => [
        'autocomplete' => $this->t('Autocomplete'),
        'select' => $this->t('Select'),
        'options' => $this->t('Options'),
      ],
      '#default_value' => $this->configuration['field_type'],
      '#required' => TRUE,
      '#ajax' => $form['#form_ajax'],
    ];

    $form['multiple'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow multiple values'),
      '#default_value' => $this->configuration['multiple'],
      '#ajax' => $form['#form_ajax'],
    ];

    if ($this->configuration['multiple']) {
      $form['multiple_operator'] = [
        '#type' => 'select',
        '#title' => $this->t('Multiple values operator'),
        '#options' => $this->multipleOperators(),
        '#default_value' => $this->configuration['multiple_operator'],
      ];
    }

    $entityTypes = $this->entityTypeManager->getDefinitions();
    $options = array_map(fn($type) => $type->getLabel(), $entityTypes);
    asort($options);

    $entityTypeId = $this->configuration['entity_type'];
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $entityTypeId,
      '#required' => TRUE,
      '#ajax' => $form['#form_ajax'],
    ];

    if ($entityTypeId) {
      $targetEntityType = $entityTypes[$entityTypeId];
      $bundleIds = $this->configuration['bundles'];
      if ($targetEntityType->hasKey('bundle') && ($bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId))) {
        $options = array_map(
          fn ($bundle) => $bundle['label'],
          $bundles
        );
        asort($options);
        $form['bundles'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Entity Bundle'),
          '#description' => $this->t('Scope this component to a specific %label type bundle.', [
            '%label' => $targetEntityType->getLabel(),
          ]),
          '#default_value' => $bundleIds,
          '#options' => $options,
          '#empty_option' => $this->t('- All -'),
        ];
      }
      $allowIdSelection = $this->configuration['field_type'] !== 'autocomplete';
      if ($allowIdSelection) {
        $options = [];
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $entities = $storage->loadMultiple();
        foreach ($entities as $entity) {
          $options[$entity->id()] = $entity->label();
        }
        $includes = $this->configuration['include'] ? $this->entityTypeManager->getStorage($entityTypeId)->loadMultiple($this->configuration['include']) : [];
        $form['include'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Include entities'),
          '#description' => $this->t('These entities will be available for selection in the filter. Leave empty to allow all entities. Be careful when using this with content entities IDs may not exist on different environments.'),
          '#target_type' => $entityTypeId,
          '#tags' => TRUE,
          '#default_value' => $includes,
        ];
        $excludes = $this->configuration['exclude'] ? $this->entityTypeManager->getStorage($entityTypeId)->loadMultiple($this->configuration['exclude']) : [];
        $form['exclude'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Exclude entities'),
          '#description' => $this->t('These entities will be excluded from selection in the filter. Leave empty to allow all entities. Be careful when using this with content entities IDs may not exist on different environments.'),
          '#target_type' => $entityTypeId,
          '#tags' => TRUE,
          '#default_value' => $excludes,
        ];
      }

      $entityPreview = $this->getEntityPreview();
      $form['entity_preview'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Entity preview'),
        '#description' => $this->t('This entity will be used to preview the component in the admin interface.'),
        '#target_type' => $entityTypeId,
        '#default_value' => $entityPreview,
      ];
      if ($bundleIds) {
        $form['entity_preview']['#selection_settings']['target_bundles'] = $bundleIds;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('bundles', array_values(array_filter($form_state->getValue('bundles') ?? [])));

    foreach (['include', 'exclude'] as $key) {
      $values = [];
      if ($entities = array_filter($form_state->getValue($key) ?? [])) {
        foreach ($entities as $entity) {
          $values[] = $entity['target_id'];
        }
      }
      $form_state->setValue($key, $values);
    }

    $entityPreview = $form_state->getValue('entity_preview');
    if ($entityPreview) {
      \Drupal::state()->set($this->getEntityPreviewKey(), $entityPreview);
    }
    $form_state->unsetValue('entity_preview');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    if (!$this->configuration['entity_type']) {
      return $form;
    }
    $value = $this->filter->getValue();
    $allowMultiple = !empty($this->configuration['multiple']);
    switch ($this->configuration['field_type']) {
      case 'autocomplete':
        $default = NULL;
        if ($value) {
          $value = explode($this->configuration['multiple_operator'], $value);
          if ($allowMultiple) {
            $default = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($value);
          }
          else {
            $value = reset($value);
            $default = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($value);
          }
        }
        $form['value'] = [
          '#type' => 'entity_autocomplete',
          '#description' => $this->filter->getDescription(),
          '#default_value' => $default,
          '#tags' => $allowMultiple,
          '#target_type' => $this->configuration['entity_type'],
          '#required' => $this->filter->isRequired(),
        ];
        if ($bundles = array_values(array_filter($this->configuration['bundles']))) {
          $form['value']['#selection_settings']['target_bundles'] = $bundles;
        }
        break;

      case 'select':
      case 'options':
        $options = [];
        $ids = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->getQuery()
          ->accessCheck(TRUE)
          ->range(0, 100);
        if ($bundles = array_values(array_filter($this->configuration['bundles']))) {
          $ids->condition($this->entityTypeManager->getDefinition($this->configuration['entity_type'])->getKey('bundle'), $bundles, 'IN');
        }
        $ids = $ids->execute();
        if ($ids) {
          $ids = array_diff($ids, $this->configuration['exclude'] ?? []);
          if ($this->configuration['include'] && is_array($this->configuration['include'])) {
            $ids = array_intersect($ids, $this->configuration['include']);
          }
          $entities = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($ids);
          foreach ($entities as $entity) {
            $options[$entity->id()] = $entity->label();
          }
        }
        $fieldType = $this->configuration['field_type'] === 'select' ? 'select' : ($allowMultiple ? 'checkboxes' : 'radios');
        $defaultValue = $value ?? [];
        if ($allowMultiple && is_string($defaultValue)) {
          $defaultValue = explode($this->configuration['multiple_operator'], $defaultValue);
        }
        if (!$allowMultiple && is_array($defaultValue)) {
          $defaultValue = reset($defaultValue) ?: '';
        }
        asort($options);
        if ($fieldType === 'radios') {
          $options = ['' => $this->t('- None -')] + $options;
        }
        $form['value'] = [
          '#type' => $fieldType,
          '#description' => $this->filter->getDescription(),
          '#default_value' => $defaultValue,
          '#options' => $options,
          '#empty_option' => $this->t('- Select -'),
          '#multiple' => $allowMultiple,
          '#target_type' => $this->configuration['entity_type'],
          '#required' => $this->filter->isRequired(),
        ];
        break;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValue(array $value, array $form, FormStateInterface $form_state): ?string {
    if (empty($value['value'])) {
      return NULL;
    }
    $value = $value['value'];
    $allowMultiple = !empty($this->configuration['multiple']);
    if (is_array($value) && !$allowMultiple) {
      $value = [reset($value)];
    }
    if (!$allowMultiple) {
      return $value;
    }
    switch ($this->configuration['field_type']) {
      case 'autocomplete':
        return implode($this->configuration['multiple_operator'], array_map(fn($item) => $item['target_id'], $value)) ?? NULL;
    }
    return implode($this->configuration['multiple_operator'], (array) $value) ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(?string $value = NULL): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntities(): array {
    if ($this->filter->getComponent()->isComponentPreview()) {
      $entityPreview = $this->getEntityPreview();
      return $entityPreview ? [$entityPreview->id() => $entityPreview] : [];
    }
    $value = $this->filter->getValue();
    if (!$value) {
      return [];
    }
    $values = explode($this->configuration['multiple_operator'], $value);
    if ($this->configuration['multiple']) {
      return $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($values);
    }
    $id = reset($values);
    return [$id => $this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($id)];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId(): string {
    return $this->configuration['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityBundles(): array {
    return $this->configuration['bundles'] ?? [];
  }

  /**
   * Get the entity set for preview.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or null.
   */
  protected function getEntityPreview(): ?EntityInterface {
    $entityId = \Drupal::state()->get($this->getEntityPreviewKey());
    if ($entityId && $this->configuration['entity_type']) {
      return $this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($entityId);
    }
    return NULL;
  }

  /**
   * Get the key to store the entity preview in state.
   *
   * @return string|null
   *   The state key.
   */
  protected function getEntityPreviewKey(): ?string {
    return 'neo_alchemist.' . $this->filter->getComponent()->id() . '.filter.' . $this->filter->uuid() . '.preview_entity';
  }

}
