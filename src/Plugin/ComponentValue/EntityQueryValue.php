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
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Event\ComponentValueEntityQueryEvent;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
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
final class EntityQueryValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

  use DependencySerializationTrait {
    __sleep as traitSleep;
  }
  use ComponentValueProcessingModeTrait;

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
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * The children-match mapper.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
   */
  protected ChildrenMatchMapper $childrenMatchMapper;

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
    MatcherReference $matcher_reference,
    ChildrenMatchMapper $children_match_mapper,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->eventDispatcher = $event_dispatcher;
    $this->matcherReference = $matcher_reference;
    $this->childrenMatchMapper = $children_match_mapper;
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
      $container->get('neo_alchemist.matcher_reference'),
      $container->get('neo_alchemist.children_match_mapper')
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
      'filter_entity_include_children' => FALSE,
      'filter_entity_include_parents' => FALSE,
      'filter_parent' => '',
      'filter_parent_term' => 0,
      'filter_level' => 1,
      'start' => 0,
      'length' => 10,
      'length_filter' => '',
      'paging' => FALSE,
    ] + ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    if ($entityTypeId = $this->configuration['entity_type']) {
      $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
      $typeLabel = $definition ? $definition->getLabel() : $entityTypeId;
      if ($bundle = $this->configuration['bundle']) {
        $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
        $summary[] = $this->t('Queries %bundle (@type)', [
          '%bundle' => $bundles[$bundle]['label'] ?? $bundle,
          '@type' => $typeLabel,
        ]);
      }
      else {
        $summary[] = $this->t('Queries %type', ['%type' => $typeLabel]);
      }
    }
    if ($sortField = $this->configuration['sort_field']) {
      $summary[] = $this->configuration['sort_direction'] === 'DESC'
        ? $this->t('Newest by @field', ['@field' => explode(':', $sortField)[0]])
        : $this->t('Oldest by @field', ['@field' => explode(':', $sortField)[0]]);
    }
    if ($filterEntity = $this->configuration['filter_entity']) {
      $summary[] = $this->t('Filtered by @field', [
        '@field' => explode(':', $filterEntity)[0],
      ]);
    }
    return array_merge($summary, $this->childrenMatchMapper->summary($this->shape, $this->configuration));
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    // The entity type and bundle selects, then the mapping table they scope.
    $form = $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
    // The rest of the query — sort, filters, range — refines which entities
    // come back without changing what kind they are, so it does not affect the
    // scope and stays below the mapping table where it has always been.
    $form = $this->buildQueryRefinementForm($form, $form_state, $wrapperId);
    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $wrapperId = $form['#id'];
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
      return new ChildrenMatchScope($entityTypeId, $bundle ?: NULL);
    }
    return NULL;
  }

  /**
   * The query controls that refine an already-scoped result set.
   */
  protected function buildQueryRefinementForm(array $form, FormStateInterface $form_state, string $wrapperId): array {
    $entityTypeId = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];
    $entityTypes = $this->entityTypeManager->getDefinitions();
    if ($entityTypeId && isset($entityTypes[$entityTypeId])) {
      $entityType = $entityTypes[$entityTypeId];
      $form['sort_field'] = [
        '#type' => 'neo_field_select',
        '#title' => $this->t('Sort by field'),
        '#component' => $this->shape->getComponent()->id(),
        '#prop' => $this->shape->getRootShape()->getName(),
        '#shape' => $this->shape->id(),
        '#all' => TRUE,
        // The sort fields belong to the entity type being QUERIED, which is
        // not the type the component is attached to.
        '#entity_type' => $entityTypeId,
        '#bundle' => $bundle,
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
        '#type' => 'neo_field_select',
        '#title' => $this->t('Sort by field (secondary)'),
        '#component' => $this->shape->getComponent()->id(),
        '#prop' => $this->shape->getRootShape()->getName(),
        '#shape' => $this->shape->id(),
        '#all' => TRUE,
        // The sort fields belong to the entity type being QUERIED, which is
        // not the type the component is attached to.
        '#entity_type' => $entityTypeId,
        '#bundle' => $bundle,
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
          '#id' => $wrapperId . '-filter-entity',
        ];

        // Only meaningful when the current entity is a taxonomy term, since the
        // hierarchy expansion walks the term tree.
        if ($entity->getEntityTypeId() === 'taxonomy_term') {
          $termStates = ['visible' => ['#' . $wrapperId . '-filter-entity' => ['!value' => '']]];
          $form['filter_entity_include_children'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Include child terms'),
            '#description' => $this->t('Also match results referencing any descendant of the current term (e.g. viewing "Communities" also returns projects tagged with "Single Family").'),
            '#default_value' => $this->configuration['filter_entity_include_children'] ?? FALSE,
            '#states' => $termStates,
          ];
          $form['filter_entity_include_parents'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Include parent terms'),
            '#description' => $this->t('Also match results referencing any ancestor of the current term.'),
            '#default_value' => $this->configuration['filter_entity_include_parents'] ?? FALSE,
            '#states' => $termStates,
          ];
        }
      }

      if ($entityTypeId === 'taxonomy_term') {
        $form['filter_parent'] = [
          '#type' => 'select',
          '#title' => $this->t('Term hierarchy'),
          '#description' => $this->t('Optionally restrict the results to a level of the vocabulary hierarchy.'),
          '#options' => [
            'root' => $this->t('Top level only (terms with no parent)'),
            'current' => $this->t('Children of the current term, else top level'),
            'term' => $this->t('Children of a specific term'),
            'level' => $this->t('All terms at a specific level'),
          ],
          '#empty_option' => $this->t('- Any depth -'),
          '#default_value' => $this->configuration['filter_parent'] ?? NULL,
          '#id' => $wrapperId . '-filter-parent',
        ];

        // Both of the options below resolve against a single vocabulary, so
        // they are only offered once a bundle has been chosen.
        if ($bundle) {
          $termOptions = [];
          $maxLevel = 0;
          foreach ($this->loadTermTreeRows($bundle) as $row) {
            $termOptions[(int) $row->tid] = str_repeat('- ', (int) $row->depth) . $row->name;
            $maxLevel = max($maxLevel, (int) $row->depth + 1);
          }

          $parentTerm = (int) ($this->configuration['filter_parent_term'] ?? 0);
          $form['filter_parent_term'] = [
            '#type' => 'select',
            '#title' => $this->t('Parent term'),
            '#description' => $this->t('Return the direct children of this term.'),
            '#options' => $termOptions,
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => isset($termOptions[$parentTerm]) ? $parentTerm : '',
            '#states' => [
              'visible' => [
                '#' . $wrapperId . '-filter-parent' => ['value' => 'term'],
              ],
            ],
          ];

          $levelOptions = [];
          foreach (range(1, max($maxLevel, 1)) as $level) {
            $levelOptions[$level] = $level;
          }
          $form['filter_level'] = [
            '#type' => 'select',
            '#title' => $this->t('Level'),
            '#description' => $this->t('Return every term at this depth of the hierarchy, regardless of parent. Level 1 is the top level.'),
            '#options' => $levelOptions,
            '#default_value' => (int) ($this->configuration['filter_level'] ?? 1),
            '#states' => [
              'visible' => [
                '#' . $wrapperId . '-filter-parent' => ['value' => 'level'],
              ],
            ],
          ];
        }
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
        // Paging needs a positive page size (pager(0) is not valid), so the
        // "all results" option is only offered when paging is off.
        $paging = !empty($this->configuration['paging']);
        $form['length'] = [
          '#type' => 'number',
          '#title' => $this->t('Length'),
          '#description' => $paging
            ? $this->t('The number of results per page.')
            : $this->t('The number of results to return. Use <em>0</em> to return all results.'),
          '#default_value' => $this->configuration['length'],
          '#min' => $paging ? 1 : 0,
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
   * Loads a vocabulary's term tree as lightweight rows.
   *
   * The rows carry ->tid, ->name and ->depth in hierarchical order, which is
   * what the indented parent select and the level resolution both need.
   * Passing TRUE for loadTree()'s $load_entities would drop ->depth.
   *
   * @param string $vid
   *   The vocabulary ID. An empty value yields no rows.
   * @param int $depth
   *   The maximum depth to load, or 0 for the whole tree.
   *
   * @return object[]
   *   The tree rows.
   */
  protected function loadTermTreeRows(string $vid, int $depth = 0): array {
    if (!$vid) {
      return [];
    }
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    return $storage->loadTree($vid, 0, $depth ?: NULL, FALSE);
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
        $length = $this->shape->isIterable() ? (int) $this->configuration['length'] : 1;
        if ($lengthFilter = $this->configuration['length_filter']) {
          $filter = $this->shape->getComponent()->getFilter($lengthFilter);
          if ($filter->getPluginId() === 'number') {
            if ($filterValue = $filter->getProcessedValue()) {
              $length = (int) trim($filterValue);
            }
          }
        }
        $start = (int) $this->configuration['start'];
        if ($this->shape->isIterable() && $this->configuration['paging']) {
          // A pager always needs a positive page size; "all results" is not a
          // meaningful page size, so fall back to the configured default.
          $query->pager($length > 0 ? $length : 10);
        }
        elseif ($length > 0) {
          $query->range($start, $length);
        }
        elseif ($start) {
          // Length 0 means "all results". There is no unbounded range, so an
          // offset is expressed with the largest length the database accepts.
          $query->range($start, PHP_INT_MAX);
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

          $filterIds = [$entity->id()];
          if ($entity->getEntityTypeId() === 'taxonomy_term') {
            /** @var \Drupal\taxonomy\TermStorageInterface $termStorage */
            $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
            if (!empty($this->configuration['filter_entity_include_children'])) {
              // loadTree() returns all descendants of the given parent term.
              foreach ($termStorage->loadTree($entity->bundle(), (int) $entity->id()) as $child) {
                $filterIds[] = $child->tid;
              }
            }
            if (!empty($this->configuration['filter_entity_include_parents'])) {
              // loadAllParents() returns the term itself plus every ancestor.
              foreach ($termStorage->loadAllParents((int) $entity->id()) as $parent) {
                $filterIds[] = $parent->id();
              }
            }
            $filterIds = array_values(array_unique($filterIds));
          }

          if (count($filterIds) > 1) {
            $query->condition($filterField, $filterIds, 'IN');
          }
          else {
            $query->condition($filterField, reset($filterIds), '=');
          }
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

            case 'term':
              // An unconfigured parent term falls back to 0, which is the top
              // level — never the whole vocabulary.
              $query->condition('parent', (int) ($this->configuration['filter_parent_term'] ?? 0), '=');
              break;

            case 'level':
              // Depth is not a queryable field, so resolve the level to term
              // IDs first. loadTree() depth is 0-based, so a 1-based level
              // matches rows at depth level - 1.
              $level = max(1, (int) ($this->configuration['filter_level'] ?? 1));
              $tids = [];
              foreach ($this->loadTermTreeRows($bundle, $level) as $row) {
                if ((int) $row->depth === $level - 1) {
                  $tids[] = (int) $row->tid;
                }
              }
              // With no vocabulary selected, or no terms at that level, match
              // nothing rather than falling through to every term.
              $query->condition($entityType->getKey('id'), $tids ?: [0], 'IN');
              break;
          }
        }
        if ($this->configuration['shape_published']) {
          // Pre-scoping the source window with the same flag the mapper
          // filters on. The mapper is still the authority — this only stops
          // unpublished entities consuming slots in the range/pager window
          // before it gets to drop them.
          //
          // Publishable entity types expose this as the "published" key; only
          // some (e.g. node) also alias it as "status". Taxonomy terms do not,
          // so testing "status" alone silently skipped the condition for them
          // and let unpublished terms consume slots in the range window.
          $statusKey = $entityType->getKey('published') ?: $entityType->getKey('status');
          if ($statusKey) {
            $query->condition($statusKey, 1);
          }
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
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $query = $this->getEntityQuery();
    if (!$query) {
      return ChildrenMatchResult::unavailable();
    }
    $entities = [];
    if ($ids = $query->execute()) {
      $storage = $this->entityTypeManager->getStorage($this->configuration['entity_type']);
      $entities = $storage->loadMultiple($ids);
    }

    $definition = $this->entityTypeManager->getDefinition($this->configuration['entity_type']);
    $this->shape->getCacheableMetadata()->addCacheTags($definition->getListCacheTags());

    return ChildrenMatchResult::of($entities);
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
