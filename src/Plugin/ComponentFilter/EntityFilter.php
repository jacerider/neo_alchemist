<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentFilter;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
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
      'multiple_limit' => 0,
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
    if ($limit = $this->getMultipleLimit()) {
      $summary[] = $this->t('Maximum values: %limit', ['%limit' => $limit]);
    }
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

      $form['multiple_limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum number of values'),
        '#description' => $this->t('The most values that may be selected for this filter. Leave at 0 for no limit.'),
        '#min' => 0,
        '#step' => 1,
        '#default_value' => $this->getMultipleLimit(),
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
          '#ajax' => $form['#form_ajax'],
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
        if ($bundleIds) {
          $form['include']['#selection_settings']['target_bundles'] = $bundleIds;
          $form['exclude']['#selection_settings']['target_bundles'] = $bundleIds;
        }
      }

      $allowMultiple = !empty($this->configuration['multiple']);
      $entityPreviews = $this->getEntityPreviews();
      $form['entity_preview'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Entity preview'),
        '#description' => $allowMultiple
          ? $this->t('These entities will be used to preview the component in the admin interface.')
          : $this->t('This entity will be used to preview the component in the admin interface.'),
        '#target_type' => $entityTypeId,
        // A tagged autocomplete takes a list of entities, a single-value one
        // takes a single entity. Toggling "Allow multiple values" off with
        // several previews already saved keeps the first.
        '#default_value' => $allowMultiple ? array_values($entityPreviews) : (reset($entityPreviews) ?: NULL),
        '#tags' => $allowMultiple,
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

    // Kept an int for the schema's sake: the number element submits a string,
    // and the element is absent entirely when multiple values are off.
    $form_state->setValue('multiple_limit', max(0, (int) $form_state->getValue('multiple_limit')));

    foreach (['include', 'exclude'] as $key) {
      $values = [];
      if ($entities = array_filter($form_state->getValue($key) ?? [])) {
        foreach ($entities as $entity) {
          $values[] = $entity['target_id'];
        }
      }
      $form_state->setValue($key, $values);
    }

    $entityPreviewIds = $this->normalizeIds($form_state->getValue('entity_preview'));
    if ($entityPreviewIds) {
      \Drupal::state()->set($this->getEntityPreviewKey(), $entityPreviewIds);
    }
    else {
      // Emptying the field has to clear the stored preview. Skipping the write
      // on an empty value left the previous entity in state, which made a
      // preview impossible to remove once set.
      \Drupal::state()->delete($this->getEntityPreviewKey());
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
          '#required' => $this->filter->isRequired() && !$this->filter->isEditable(),
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
    // None of these widgets can enforce a cardinality themselves — tagged
    // autocompletes, multi-selects and checkboxes all accept unlimited values —
    // so the limit is advertised here and enforced in validateForm().
    if ($allowMultiple && isset($form['value']) && ($limit = $this->getMultipleLimit())) {
      $hint = $this->formatPlural($limit, 'Select at most 1 value.', 'Select at most @count values.');
      $description = (string) ($form['value']['#description'] ?? '');
      $form['value']['#description'] = trim($description . ' ' . $hint);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $limit = $this->getMultipleLimit();
    if (!$limit || empty($this->configuration['multiple']) || !isset($form['value'])) {
      return;
    }
    $selected = count($this->normalizeIds($form_state->getValue('value')));
    if ($selected > $limit) {
      // @count is bound to $limit by formatPlural, so the selected total needs
      // its own placeholder.
      $form_state->setError($form['value'], $this->formatPlural(
        $limit,
        '%title: at most 1 value may be selected, but @selected were selected.',
        '%title: at most @count values may be selected, but @selected were selected.',
        [
          '%title' => $this->filter->label(),
          '@selected' => $selected,
        ]
      ));
    }
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
    if (!$allowMultiple) {
      if (is_array($value)) {
        // A widget left in multi/tags mode can submit an array for a
        // single-value filter; reduce to the first entry. (The old wrap in
        // [reset($value)] returned an array from this ?string method — a
        // guaranteed TypeError.)
        $value = reset($value);
        if (is_array($value)) {
          // Autocomplete rows carry ['target_id' => ...].
          $value = $value['target_id'] ?? reset($value);
        }
      }
      return $value === NULL || $value === FALSE || $value === '' ? NULL : (string) $value;
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
      return $this->getEntityPreviews();
    }
    $value = $this->filter->getValue();
    if (!$value) {
      return [];
    }
    $values = explode($this->configuration['multiple_operator'], $value);
    if ($this->configuration['multiple']) {
      // Also capped at read time. The limit lives in component config while the
      // values live on each component instance, so lowering the limit has to
      // constrain instances that were saved under the old one rather than wait
      // for each to be re-saved.
      if ($limit = $this->getMultipleLimit()) {
        $values = array_slice($values, 0, $limit);
      }
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
   * Get the entities set for preview.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The preview entities, keyed by entity ID. Empty if none are set, the
   *   filter has no entity type yet, or the saved entities no longer exist.
   */
  protected function getEntityPreviews(): array {
    if (!$this->configuration['entity_type']) {
      return [];
    }
    // Normalised on read as well as on write: state written before this was
    // fixed holds the raw ['target_id' => id] rows a tagged autocomplete
    // submits, and feeding those to loadMultiple() is a TypeError deep inside
    // the entity storage cache. Repairing them here spares every existing
    // component a re-save.
    $entityIds = $this->normalizeIds(\Drupal::state()->get($this->getEntityPreviewKey()));
    if (!$entityIds) {
      return [];
    }
    // The preview stands in for a real filter value, so it obeys the same cap.
    if ($this->configuration['multiple'] && ($limit = $this->getMultipleLimit())) {
      $entityIds = array_slice($entityIds, 0, $limit);
    }
    return $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($entityIds);
  }

  /**
   * The maximum number of values this filter accepts.
   *
   * @return int
   *   The limit, or 0 when unlimited. Always 0 when the filter is single-value,
   *   where cardinality is already implicitly one.
   */
  protected function getMultipleLimit(): int {
    return max(0, (int) ($this->configuration['multiple_limit'] ?? 0));
  }

  /**
   * Normalise a submitted or stored value to a flat list of entity IDs.
   *
   * Accepts every shape these values take: a bare scalar ID (single-value
   * autocomplete, and previews saved before the field accepted multiple
   * values), a list of ['target_id' => id] rows (tagged autocomplete), or a
   * plain list of IDs (multi-select, checkboxes).
   *
   * @param mixed $value
   *   The raw submitted or stored value.
   *
   * @return array
   *   A flat list of entity IDs, empty entries removed.
   */
  private function normalizeIds(mixed $value): array {
    $ids = [];
    foreach (is_array($value) ? $value : [$value] as $item) {
      $id = is_array($item) ? ($item['target_id'] ?? NULL) : $item;
      // Unchecked checkboxes submit 0 or ''; no entity ID is ever falsy.
      if (!is_array($id) && !empty($id)) {
        $ids[] = $id;
      }
    }
    return $ids;
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
