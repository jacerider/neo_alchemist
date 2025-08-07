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
      'entity_type' => '',
      'bundles' => [],
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

      $entities = [];
      if ($this->configuration['multiple']) {
        $entities = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($values);
      }
      else {
        $value = reset($values);
        $entities = [$this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($value)];
      }
      $entities = array_filter($entities);
      if ($entities) {
        return implode(', ', array_map(fn($entity) => $entity->label(), $entities));
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
        // 'select' => $this->t('Select'),
        // 'options' => $this->t('Options'),
      ],
      '#default_value' => $this->configuration['field_type'],
      '#required' => TRUE,
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
      $target_entity_type = $entityTypes[$entityTypeId];
      if ($target_entity_type->hasKey('bundle') && ($bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId))) {
        $options = array_map(
          fn ($bundle) => $bundle['label'],
          $bundles
        );
        asort($options);
        $form['bundles'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Entity Bundle'),
          '#description' => $this->t('Scope this component to a specific %label type bundle.', [
            '%label' => $target_entity_type->getLabel(),
          ]),
          '#default_value' => $this->configuration['bundles'],
          '#options' => $options,
          '#empty_option' => $this->t('- All -'),
          '#required' => TRUE,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('bundles', array_values(array_filter($form_state->getValue('bundles') ?? [])));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    if (!$this->configuration['entity_type']) {
      return $form;
    }
    $value = $this->filter->getValue();
    switch ($this->configuration['field_type']) {
      case 'autocomplete':
        $default = NULL;
        if ($value) {
          $value = explode($this->configuration['multiple_operator'], $value);
          if ($this->configuration['multiple']) {
            $default = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->loadMultiple($value);
          }
          else {
            $value = reset($value);
            $default = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($value);
          }
        }
        $form['value'] = [
          '#type' => 'entity_autocomplete',
          '#default_value' => $default,
          '#tags' => !empty($this->configuration['multiple']),
          '#target_type' => $this->configuration['entity_type'],
          '#selection_settings' => [
            'target_bundles' => array_values(array_filter($this->configuration['bundles'])),
          ],
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
    if (is_array($value)) {
      if (!$this->configuration['multiple']) {
        $value = [reset($value)];
      }
      return implode($this->configuration['multiple_operator'], array_map(fn($item) => $item['target_id'], $value)) ?? NULL;
    }
    return $value;
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

}
