<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\Event\ComponentValueEntityQueryEvent;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\MatcherReference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'entity_query',
  label: new TranslatableMarkup('Entity Query'),
  description: new TranslatableMarkup('Use the results of an entity query to provide values from the queried entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class EntityQueryValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait {
    __sleep as traitSleep;
  }
  use ComponentValueChildrenMatchTrait;

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
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * The entity query.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface|null
   */
  protected ?QueryInterface $entityQuery = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EventDispatcherInterface $event_dispatcher,
    MatcherField $matcher_field,
    MatcherReference $matcher_reference,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->eventDispatcher = $event_dispatcher;
    $this->matcherField = $matcher_field;
    $this->matcherReference = $matcher_reference;
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
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('event_dispatcher'),
      $container->get('neo_alchemist.matcher_field'),
      $container->get('neo_alchemist.matcher_reference')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => '',
      'bundle' => '',
      'sort_field' => '',
      'sort_direction' => 'ASC',
      'sort_field_2' => '',
      'sort_direction_2' => 'ASC',
      'filter_entity' => '',
      'filter_parent' => '',
      'start' => 0,
      'length' => 10,
      'length_filter' => '',
      'paging' => FALSE,
      'continue' => FALSE,
    ] + $this->childrenMatchDefaultConfiguration();
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $entityTypeId = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];

    $entityTypes = $this->entityTypeManager->getDefinitions();
    $options = [];
    foreach ($entityTypes as $type) {
      if ($type instanceof ContentEntityTypeInterface) {
        $options[$type->id()] = $type->getLabel();
      }
    }
    asort($options);
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Type'),
      '#description' => $this->t('Scope this component to a specific entity type.'),
      '#default_value' => $entityTypeId,
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($entityTypeId && isset($entityTypes[$entityTypeId])) {
      $entityType = $entityTypes[$entityTypeId];
      if ($entityType->hasKey('bundle')) {
        if ($bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId)) {
          $options = array_map(
            fn ($bundle) => $bundle['label'],
            $bundles
          );
          asort($options);
          $form['bundle'] = [
            '#type' => 'select',
            '#title' => $this->t('Entity Bundle'),
            '#default_value' => $bundle,
            '#options' => $options,
            '#empty_option' => $this->t('- All -'),
            '#ajax' => [
              'callback' => [static::class, 'refreshAjax'],
              'wrapper' => $wrapperId,
            ],
          ];
        }
      }

      // Add shape fields.
      $form += $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, $entityTypeId, $bundle, $this->configuration);

      $form['sort_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Sort by field'),
        '#options' => $this->matcherField->getMatchesAsOptions($this->shape, $entityTypeId, $bundle, NULL, TRUE),
        '#empty_option' => $this->t('- Default -'),
        '#default_value' => $this->configuration['sort_field'] ?? NULL,
        '#id' => $wrapperId . '-sort-field',
      ];

      $form['sort_direction'] = [
        '#type' => 'select',
        '#title' => $this->t('Sort direction'),
        '#options' => [
          'ASC' => $this->t('Ascending'),
          'DESC' => $this->t('Descending'),
        ],
        '#default_value' => $this->configuration['sort_direction'],
        '#states' => [
          'visible' => [
            '#' . $wrapperId . '-sort-field' => ['!value' => ''],
          ],
        ],
      ];

      $form['sort_field_2'] = [
        '#type' => 'select',
        '#title' => $this->t('Sort by field (secondary)'),
        '#options' => $this->matcherField->getMatchesAsOptions($this->shape, $entityTypeId, $bundle, NULL, TRUE),
        '#empty_option' => $this->t('- Default -'),
        '#default_value' => $this->configuration['sort_field_2'] ?? NULL,
        '#id' => $wrapperId . '-sort-field-2',
      ];

      $form['sort_direction_2'] = [
        '#type' => 'select',
        '#title' => $this->t('Sort direction (secondary)'),
        '#options' => [
          'ASC' => $this->t('Ascending'),
          'DESC' => $this->t('Descending'),
        ],
        '#default_value' => $this->configuration['sort_direction_2'],
        '#states' => [
          'visible' => [
            '#' . $wrapperId . '-sort-field-2' => ['!value' => ''],
          ],
        ],
      ];

      $entity = $this->shape->getEntity();
      $options = $this->matcherReference->getReferencesAsOptions($entityTypeId, $bundle, $entity->getEntityTypeId(), $entity->bundle());
      if ($options) {
        $form['filter_entity'] = [
          '#type' => 'select',
          '#title' => $this->t('Filter by entity reference'),
          '#description' => $this->t('Optionally filter the results by a specific entity reference field on the current entity. This will limit the results to only those referenced entities.'),
          '#options' => $options,
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $this->configuration['filter_entity'] ?? NULL,
        ];
      }

      if ($entityTypeId === 'taxonomy_term') {
        $form['filter_parent'] = [
          '#type' => 'select',
          '#title' => $this->t('Filter by parent term'),
          '#description' => $this->t('Optionally filter the results by a specific parent term.'),
          '#options' => [
            'root' => $this->t('Root'),
            'current' => $this->t('Current Term else Root'),
          ],
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $this->configuration['filter_parent'] ?? NULL,
        ];
      }

      $form['start'] = [
        '#type' => 'number',
        '#title' => $this->t('Start'),
        '#description' => $this->t('The starting index of the results to return.'),
        '#default_value' => $this->configuration['start'],
        '#min' => 0,
        '#step' => 1,
      ];

      if ($this->shape->isIterable()) {
        $form['length'] = [
          '#type' => 'number',
          '#title' => $this->t('Length'),
          '#description' => $this->t('The number of results to return.'),
          '#default_value' => $this->configuration['length'],
          '#min' => 1,
          '#step' => 1,
        ];

        $numberFilters = $this->getShape()->getComponent()->getFilters('number');
        if ($numberFilters) {
          $options = array_map(fn($filter) => $filter->label(), $numberFilters);
          asort($options);
          $form['length_filter'] = [
            '#type' => 'select',
            '#title' => $this->t('Length filter'),
            '#description' => $this->t('Optionally use a number filter to set the length. This can be used to dynamically limit the number of results returned.'),
            '#options' => $options,
            '#empty_option' => $this->t('- None -'),
            '#default_value' => $this->configuration['length_filter'] ?? NULL,
          ];
        }

        $form['start']['#disabled'] = !empty($this->configuration['paging']);

        $form['paging'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable paging'),
          '#description' => $this->t('The pager can be rendered using the <em>Entity Query Pager</em> slot.'),
          '#default_value' => $this->configuration['paging'],
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];
      }

      $form['continue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Continue when no results'),
        '#description' => $this->t('This will allow any following value providers to be processed if the view returns no results.'),
        '#default_value' => $this->configuration['continue'],
      ];
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
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Gets the entity query for the component value.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The entity query.
   */
  protected function getEntityQuery(): ?QueryInterface {
    if (!$this->entityQuery) {
      $entityTypeId = $this->configuration['entity_type'];
      if ($entityTypeId) {
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $query = $storage->getQuery();
        $query->accessCheck(TRUE);
        if ($sortField = $this->configuration['sort_field']) {
          $sortField = str_replace('.', '.entity.', $sortField);
          $sortDirection = $this->configuration['sort_direction'] ?? 'ASC';
          $query->sort($sortField, $sortDirection);
        }
        if ($sortField = $this->configuration['sort_field_2']) {
          $sortField = str_replace('.', '.entity.', $sortField);
          $sortDirection = $this->configuration['sort_direction_2'] ?? 'ASC';
          $query->sort($sortField, $sortDirection);
        }
        $length = $this->shape->isIterable() ? $this->configuration['length'] : 1;
        if ($lengthFilter = $this->configuration['length_filter']) {
          $filter = $this->shape->getComponent()->getFilter($lengthFilter);
          if ($filter->getPluginId() === 'number') {
            if ($filterValue = $filter->getProcessedValue()) {
              $length = (int) trim($filterValue);
            }
          }
        }
        if ($this->shape->isIterable() && $this->configuration['paging']) {
          $query->pager($length);
        }
        else {
          $query->range($this->configuration['start'], $length);
        }
        $bundle = $this->configuration['bundle'];
        if ($bundle) {
          if ($entityType->hasKey('bundle')) {
            $query->condition($entityType->getKey('bundle'), $bundle);
          }
        }
        $entity = $this->shape->getEntity();
        if ($this->configuration['filter_entity'] && !$entity->isNew()) {
          $filterField = $this->configuration['filter_entity'];
          $searchFor = ':entity';
          $lastPos = strrpos($filterField, $searchFor);
          if ($lastPos !== FALSE) {
            $filterField = substr($filterField, 0, $lastPos);
          }
          $filterField = str_replace('.', '.entity.', $filterField);
          $query->condition($filterField, $entity->id(), '=');
        }
        if ($entityTypeId === 'taxonomy_term' && $this->configuration['filter_parent']) {
          switch ($this->configuration['filter_parent']) {
            case 'root':
              $query->condition('parent', 0, '=');
              break;

            case 'current':
              $entity = $this->shape->getEntity();
              if (!$entity->isNew() && $entity->getEntityTypeId() === 'taxonomy_term') {
                $query->condition('parent', $entity->id(), '=');
              }
              else {
                $query->condition('parent', 0, '=');
              }
              break;
          }
        }
        if ($this->configuration['shape_published'] && $entityType->hasKey('status')) {
          $query->condition($entityType->getKey('status'), 1);
        }
        $event = new ComponentValueEntityQueryEvent($this->shape, $query);
        $this->eventDispatcher->dispatch($event, ComponentValueEntityQueryEvent::EVENT_NAME);
        $this->entityQuery = $query;
        // Set a context for use by slots.
        $this->shape->getComponent()->setPropShapeContext('entity_query', $this->shape, $query);
      }
    }
    return $this->entityQuery;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    if (!$this->shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $value;
    }
    if ($query = $this->getEntityQuery()) {
      $entities = [];
      if ($ids = $query->execute()) {
        $storage = $this->entityTypeManager->getStorage($this->configuration['entity_type']);
        $entities = $ids ? $storage->loadMultiple($ids) : [];
      }

      $definition = $this->entityTypeManager->getDefinition($this->configuration['entity_type']);
      $this->shape->getCacheableMetadata()->addCacheTags($definition->getListCacheTags());

      $results = $this->getChildrenMatchValues($this->shape, $entities, $this->configuration);
      if (!empty($results) || empty($this->configuration['continue'])) {
        $value = $results;
        $this->stopFurtherProcessing();
      }
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    if ($shape->isIterable()) {
      return TRUE;
    }
    return $shape->isExpandable();
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    return array_diff($this->traitSleep(), [
      'entityQuery',
    ]);
  }

}
