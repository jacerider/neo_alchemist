<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Filter\ComponentFilterPluginEntityInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity',
  label: new TranslatableMarkup('Entity'),
  description: new TranslatableMarkup('Embed an entity display.'),
)]
final class EntitySlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

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
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

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
    EntityDisplayRepositoryInterface $entity_display_repository,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityDisplayRepository = $entity_display_repository;
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
      $container->get('entity_display.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => '',
      'bundles' => [],
      'entity' => '',
      'filter' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if ($entity_type = $this->configuration['entity_type']) {
      $entityType = $this->entityTypeManager->getDefinition($entity_type);
      $summary[] = $this->t('Entity type: @type', ['@type' => $entityType->getLabel()]);
      if ($bundles = array_values(array_filter($this->configuration['bundles']))) {
        $bundle_labels = [];
        $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type);
        foreach ($bundles as $bundle) {
          $bundle_labels[] = $bundle_info[$bundle]['label'] ?? $bundle;
        }
        $summary[] = $this->t('Bundles: @bundles', ['@bundles' => implode(', ', $bundle_labels)]);
      }
      if ($entity_id = $this->configuration['entity']) {
        $storage = $this->entityTypeManager->getStorage($entity_type);
        if ($entity = $storage->load($entity_id)) {
          $summary[] = $this->t('Entity: @link', ['@link' => $entity->toLink()->toString()]);
        }
      }
      if ($filter = $this->configuration['filter']) {
        $filters = $this->component->getFilters();
        foreach ($filters as $filter) {
          if ($filter->getPlugin() instanceof ComponentFilterPluginEntityInterface) {
            $summary[] = $this->t('Filter: @label', ['@label' => $filter->label()]);
            break;
          }
        }
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
    $entityTypeId = $this->configuration['entity_type'];

    $form['message'] = [
      '#markup' => $this->t('Content entities vary by environment. Only use this slot if you know the entity will always exist across all environments or add an entity filter and utilize it for on-demand entity selection.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];

    $options = array_map(function ($definition) {
      return $definition->getLabel();
    }, $this->entityTypeManager->getDefinitions());
    asort($options);
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $entityTypeId,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
      '#required' => TRUE,
    ];

    if (!$entityTypeId) {
      return $form;
    }

    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    $hasBundles = !empty($entityType->getBundleEntityType());
    $bundles = [];
    if ($hasBundles) {
      $options = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
      $options = array_map(fn ($bundle) => $bundle['label'], $options);
      asort($options);
      $bundles = $this->configuration['bundles'];
      $form['bundles'] = [
        '#type' => 'select',
        '#title' => $this->t('Bundles'),
        '#multiple' => TRUE,
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $bundles,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
        '#required' => TRUE,
      ];
    }

    if (!$bundles && $hasBundles) {
      return $form;
    }

    $entityId = $this->configuration['entity'];
    $form['entity'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Entity'),
      '#target_type' => $entityTypeId,
      '#default_value' => $entityId ? $this->entityTypeManager->getStorage($entityTypeId)->load($entityId) : NULL,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];
    if ($bundles) {
      $form['entity']['#selection_settings']['target_bundles'] = $bundles;
    }

    $filters = $this->component->getFilters();
    if (!$filters) {
      return $form;
    }
    $options = array_map(fn($filter) => $filter->label(), array_filter($filters, function ($filter) {
      $plugin = $filter->getPlugin();
      return $plugin instanceof ComponentFilterPluginEntityInterface && $plugin->getEntityTypeId() === $this->configuration['entity_type'];
    }));
    if (!$options) {
      return $form;
    }
    asort($options);

    $filterId = $this->configuration['filter'];
    $form['filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter'),
      '#description' => $this->t('Select a component filter to choose the entity dynamically. This will override the entity selected above.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $filterId,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];
    if ($filterId) {
      $form['entity']['#required'] = FALSE;
    }

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $entityTypeId = $this->configuration['entity_type'];
    $entityId = $this->configuration['entity'];
    $filterId = $this->configuration['filter'];
    $entity = NULL;
    if ($filterId) {
      $filter = $this->component->getFilter($filterId);
      if ($filter && $filter->getPlugin() instanceof ComponentFilterPluginEntityInterface) {
        $entities = $filter->getPlugin()->getEntities();
        if ($entities) {
          $entity = reset($entities);
        }
      }
    }
    if (!$entity && $entityId) {
      $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
    }
    if (!$entity) {
      return [];
    }
    return $this->entityTypeManager->getViewBuilder($entityTypeId)->view($entity);
  }

}
