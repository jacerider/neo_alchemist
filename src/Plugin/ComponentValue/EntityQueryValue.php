<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\EntityTypeSelectBuilder;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Event\ComponentValueEntityQueryEvent;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
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
final class EntityQueryValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait {
    __sleep as traitSleep;
  }
  use ComponentValueProcessingModeTrait;

  /**
   * How many shared-reference pair rows the form renders at a minimum.
   *
   * The list grows by one row once the last is filled and saved, so more pairs
   * stay reachable without an AJAX repeater — see buildQueryRefinementForm().
   */
  private const SHARED_FILTER_ROWS = 2;

  /**
   * Above this many host targets, score per pair instead of per target.
   *
   * Scoring resolves one query per host target, which is exact but scales with
   * the host's own tagging. A host carrying more targets than this falls back
   * to one query per pair, which costs a bounded number of queries at the price
   * of a coarser score (breadth only, every depth 1).
   */
  private const SHARED_FILTER_TARGET_LIMIT = 20;

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
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * The entity query.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface|null
   */
  protected ?QueryInterface $entityQuery = NULL;

  /**
   * The pager element this query claimed, or NULL when paging is off.
   *
   * @var int|null
   */
  protected ?int $pagerElement = NULL;

  /**
   * Match strength per candidate id, or NULL when no shared filter ran.
   *
   * Keyed by entity id, each value ['breadth' => int, 'depth' => int]:
   * how many configured pairs the candidate matched, and how many of the
   * host's targets it shares in total. Ranking reads it in
   * getChildrenMatchEntities() — the entity query cannot sort by it.
   *
   * @var array<int|string, array{breadth: int, depth: int}>|null
   */
  protected ?array $sharedScores = NULL;

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
    PagerManagerInterface $pager_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->eventDispatcher = $event_dispatcher;
    $this->matcherReference = $matcher_reference;
    $this->childrenMatchMapper = $children_match_mapper;
    $this->pagerManager = $pager_manager;
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
      $container->get('neo_alchemist.children_match_mapper'),
      $container->get('pager.manager')
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
      'filter_shared' => [],
      'filter_shared_operator' => 'or',
      'filter_shared_rank' => TRUE,
      'filter_exclude_self' => FALSE,
      'filter_parent' => '',
      'filter_parent_term' => 0,
      'filter_level' => 1,
      'start' => 0,
      'length' => 10,
      'length_filter' => '',
      'paging' => FALSE,
    ] + ChildrenMatchMapper::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Declares the wider handle: this producer reads the root shape (Tree) and
   * the prop's iterability (Schema), and hands its whole shape to the
   * ChildrenMatchMapper and the entity-query event — all beyond the
   * Context + Value + cacheability the base hands producers. Covariant return —
   * the union is a subtype of the handle, and the runtime shape is the union.
   */
  public function getShape(): ComponentShapePluginInterface {
    assert($this->shape instanceof ComponentShapePluginInterface);
    return $this->shape;
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
    if ($pairs = $this->sharedReferencePairs()) {
      // Naming the host side: that is the field the site builder recognises on
      // the page they are configuring.
      $summary[] = $this->t('Shares @op of @fields', [
        '@op' => ($this->configuration['filter_shared_operator'] ?? 'or') === 'and'
          ? $this->t('all')
          : $this->t('any'),
        '@fields' => implode(', ', array_map(
          static fn (array $pair) => explode(':', $pair['host'])[0],
          $pairs,
        )),
      ]);
      if (!empty($this->configuration['filter_shared_rank'])) {
        $summary[] = $this->t('Best match first');
      }
    }
    if (!empty($this->configuration['filter_exclude_self'])) {
      $summary[] = $this->t('Excludes the current entity');
    }
    return array_merge($summary, $this->childrenMatchMapper->summary($this->getShape(), $this->configuration));
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $wrapperId = $form['#id'];
    $entityTypeId = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];

    $ajax = ['callback' => [static::class, 'refreshAjax'], 'wrapper' => $wrapperId];
    $entityTypes = $this->entityTypeManager->getDefinitions();
    $form['entity_type'] = EntityTypeSelectBuilder::entityTypeSelect(
      $this->entityTypeManager,
      $entityTypeId,
      $ajax,
      TRUE,
      ['#description' => $this->t('Scope this component to a specific entity type.')],
    );

    if ($entityTypeId && isset($entityTypes[$entityTypeId])) {
      $entityType = $entityTypes[$entityTypeId];
      if ($entityType->hasKey('bundle') && $this->entityTypeBundleInfo->getBundleInfo($entityTypeId)) {
        $form['bundle'] = EntityTypeSelectBuilder::bundleSelect(
          $this->entityTypeBundleInfo,
          $entityTypeId,
          $bundle,
          $ajax,
          [
            '#title' => $this->t('Entity Bundle'),
            '#empty_option' => $this->t('- All -'),
          ],
        );
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
        '#prop' => $this->getShape()->getRootShape()->getName(),
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
        '#prop' => $this->getShape()->getRootShape()->getName(),
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

      // "Shares a reference with the current entity": the queried entity and
      // the host point at some of the same targets. Unlike filter_entity —
      // whose right-hand side is always the host entity itself — both sides
      // are named, because the queried entity type is configured
      // independently and is routinely not the host's own type.
      $hostOptions = $this->directReferenceOptions($entity->getEntityTypeId(), $entity->bundle());
      $queryOptions = $this->directReferenceOptions($entityTypeId, $bundle);
      if ($hostOptions && $queryOptions) {
        $pairs = $this->sharedReferencePairs();
        // A fixed row count rather than an AJAX repeater. Every #ajax in this
        // form works only because its trigger is a direct child of the
        // settings form, whose #id is the wrapper — refreshAjax() returns the
        // parent of the trigger. A nested "Add row" button would return a
        // fragment that replaces the whole provider form. Growing by one row
        // per save keeps more pairs reachable with no callback and no
        // UI-only config key.
        $rowCount = max(self::SHARED_FILTER_ROWS, count($pairs) + 1);
        // Name both sides by what they actually are on THIS component rather
        // than as "current"/"queried" entity. A site builder configuring an
        // article listing reads "Field on the Article being viewed" and knows
        // immediately which column is which; "current entity" they have to be
        // taught.
        $hostLabel = $this->targetTypeLabel($entity->getEntityTypeId(), $entity->bundle());
        $queryLabel = $this->targetTypeLabel($entityTypeId, $bundle);
        $form['filter_shared'] = [
          '#type' => 'details',
          '#title' => $this->t('Filter by shared references'),
          '#description' => $this->t('Only return results tagged with something the page being viewed is also tagged with — the usual way to build a "related content" listing. Pick a field on the left and the field on the right that has to overlap it; both must point at the same kind of entity. Leave a row empty to ignore it. A field that is empty on the page being viewed is skipped, and if every row ends up skipped the results are not filtered at all.'),
          '#open' => (bool) $pairs,
          '#tree' => TRUE,
        ];
        // A real table: the column headers carry the meaning, so the selects
        // below the first row need no repeated label. Rendering these as bare
        // #title_display => invisible selects OUTSIDE a table leaves unlabelled
        // orphan selects stacked down the form, which is unreadable.
        $form['filter_shared']['pairs'] = [
          '#type' => 'table',
          '#header' => [
            'host' => $this->t('@host field on the page being viewed', ['@host' => $hostLabel]),
            'query' => $this->t('Must share a value with this @query field', ['@query' => $queryLabel]),
          ],
        ];
        for ($delta = 0; $delta < $rowCount; $delta++) {
          foreach (['host' => $hostOptions, 'query' => $queryOptions] as $side => $sideOptions) {
            $form['filter_shared']['pairs'][$delta][$side] = [
              '#type' => 'select',
              '#title' => $side === 'host'
                ? $this->t('@host field on the page being viewed', ['@host' => $hostLabel])
                : $this->t('Must share a value with this @query field', ['@query' => $queryLabel]),
              // The column header is the visible label; this one is for screen
              // readers, which do not read table headers per cell.
              '#title_display' => 'invisible',
              '#options' => $sideOptions,
              '#empty_option' => $this->t('- None -'),
              '#default_value' => $pairs[$delta][$side] ?? '',
              // Explicit, so the stored tree stays filter_shared.<delta>.<side>
              // whatever the layout does, and so configurationValidate() can
              // setError() on an element built without form processing.
              '#parents' => array_merge($form['#parents'], ['filter_shared', $delta, $side]),
            ];
          }
        }
        $form['filter_shared']['operator'] = [
          '#type' => 'select',
          '#title' => $this->t('Combine these filters with'),
          '#options' => [
            'or' => $this->t('Any — share at least one of the rows'),
            'and' => $this->t('All — share every row'),
          ],
          '#default_value' => $this->configuration['filter_shared_operator'] ?? 'or',
          // Rendered inside the details for legibility, stored as a sibling
          // key — the explicit-#parents idiom ChildrenMatchMapper uses to keep
          // the stored tree independent of the form layout.
          '#parents' => array_merge($form['#parents'], ['filter_shared_operator']),
        ];
        $form['filter_shared']['rank'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Best match first'),
          '#description' => $this->t('Results matching more of these fields come first, then those sharing more targets, then the sort above.'),
          '#default_value' => !empty($this->configuration['filter_shared_rank']),
          '#parents' => array_merge($form['#parents'], ['filter_shared_rank']),
        ];
        if (!empty($this->configuration['paging'])) {
          // Ranking reorders the whole result set, so page 2 of a ranked
          // listing would not be the second page of anything.
          $form['filter_shared']['rank']['#disabled'] = TRUE;
          $form['filter_shared']['rank']['#description'] = $this->t('Unavailable while paging is enabled — ranking reorders the whole result set, which would leave the pages meaningless.');
        }
      }

      // Only offered when the host is one of the things being queried.
      if ($entityTypeId === $entity->getEntityTypeId()) {
        $form['filter_exclude_self'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Leave out the page being viewed'),
          '#description' => $this->t('Keep the @host the component is rendered on from appearing in its own results.', [
            '@host' => $this->targetTypeLabel($entity->getEntityTypeId(), $entity->bundle()),
          ]),
          '#default_value' => !empty($this->configuration['filter_exclude_self']),
        ];
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

      if ($this->getShape()->isIterable()) {
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
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    if (!isset($form['filter_shared']['pairs'])) {
      return;
    }
    // The submitted type/bundle, not the staged configuration: these are what
    // built the option lists now being validated, and staged settings lag a
    // mid-AJAX submit.
    $entityTypeId = (string) $form_state->getValue('entity_type', $this->configuration['entity_type']);
    $bundle = (string) $form_state->getValue('bundle', $this->configuration['bundle']);
    $entity = $this->shape->getEntity();
    $hostRefs = $this->matcherReference->getReferences($entity->getEntityTypeId(), $entity->bundle());
    $queryRefs = $this->matcherReference->getReferences($entityTypeId, $bundle ?: NULL);

    foreach ((array) $form_state->getValue('filter_shared', []) as $delta => $row) {
      if (!is_array($row)) {
        continue;
      }
      $host = (string) ($row['host'] ?? '');
      $query = (string) ($row['query'] ?? '');
      if ($host === '' && $query === '') {
        continue;
      }
      if ($host === '' || $query === '') {
        $side = $host === '' ? 'host' : 'query';
        if (isset($form['filter_shared']['pairs'][$delta][$side])) {
          $form_state->setError($form['filter_shared']['pairs'][$delta][$side], $this->t('A shared reference filter needs a field on both sides.'));
        }
        continue;
      }
      $hostTarget = $hostRefs[$host]['definition'] ?? NULL;
      $queryTarget = $queryRefs[$query]['definition'] ?? NULL;
      $hostTarget = $hostTarget?->getSetting('target_type');
      $queryTarget = $queryTarget?->getSetting('target_type');
      if ($hostTarget && $queryTarget && $hostTarget !== $queryTarget) {
        // Not a warning. The filter compares raw target ids, so mismatched
        // target types do not return nothing — they return whichever entity
        // happens to carry a colliding id. Wrong results, not no results.
        if (isset($form['filter_shared']['pairs'][$delta]['query'])) {
          $form_state->setError($form['filter_shared']['pairs'][$delta]['query'], $this->t('Both sides of a shared reference filter must point at the same kind of entity. The left field points at %host; the right field points at %query.', [
            '%host' => $hostTarget,
            '%query' => $queryTarget,
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    // The form renders a fixed number of rows; store only the complete ones,
    // re-indexed, so the saved value is a clean config sequence and the row
    // count is never persisted as data. array_key_exists, not ??: an absent
    // key means the element was never rendered (no entity type chosen yet)
    // and must not be clobbered to an empty list.
    if (array_key_exists('filter_shared', $values)) {
      $values['filter_shared'] = $this->normalizeSharedPairs($values['filter_shared']);
    }
    return $values;
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
   * Turns a MatcherReference key into an entity-query condition field.
   *
   * @param string $key
   *   The matcher key, e.g. `field_a:entity` or `field_a.field_b:entity`.
   *
   * @return string
   *   The condition field, e.g. `field_a` or `field_a.entity.field_b`.
   */
  protected function conditionField(string $key): string {
    $searchFor = ':entity';
    $lastPos = strrpos($key, $searchFor);
    if ($lastPos !== FALSE) {
      $key = substr($key, 0, $lastPos);
    }
    return str_replace('.', '.entity.', $key);
  }

  /**
   * The human label for an entity type/bundle, for use in form copy.
   *
   * @param string $entityTypeId
   *   The entity type.
   * @param string|null $bundle
   *   The bundle, or NULL/'' to label the entity type itself.
   *
   * @return string
   *   The bundle label where there is one, else the entity type label.
   */
  protected function targetTypeLabel(string $entityTypeId, ?string $bundle): string {
    if ($bundle) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
      if (isset($bundles[$bundle]['label'])) {
        return (string) $bundles[$bundle]['label'];
      }
    }
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    return $definition ? (string) $definition->getLabel() : $entityTypeId;
  }

  /**
   * Reference options for one side of a shared-reference pair.
   *
   * Narrowed to DIRECT references — no multi-hop paths, no language references.
   * Both are deliberate and for the same reason: a multi-hop option is labelled
   * by its last field only, so `revision_uid.langcode` and `uid.langcode` both
   * render as "Language (langcode)" and a site builder cannot tell the paths
   * apart. Offering choices nobody can distinguish is worse than not offering
   * them. A hand-written multi-hop key still resolves — getReferenceField()
   * walks the path and conditionField() rewrites it — it is simply not offered.
   *
   * @param string $entityTypeId
   *   The entity type to list reference fields for.
   * @param string|null $bundle
   *   The bundle, or NULL/'' for the type's base fields.
   *
   * @return array
   *   Grouped select options.
   */
  protected function directReferenceOptions(string $entityTypeId, ?string $bundle): array {
    $options = $this->matcherReference->getReferencesAsOptions($entityTypeId, $bundle ?: NULL);
    foreach ($options as $group => $groupOptions) {
      foreach (array_keys($groupOptions) as $key) {
        if (str_contains((string) $key, '.') || str_ends_with((string) $key, ':language')) {
          unset($options[$group][$key]);
        }
      }
      if (!$options[$group]) {
        unset($options[$group]);
      }
    }
    return $options;
  }

  /**
   * Drops incomplete rows from a shared-reference pair list and re-indexes.
   *
   * @param mixed $rows
   *   The raw rows, from configuration or from submitted form values.
   *
   * @return array<int, array{host: string, query: string}>
   *   The complete pairs.
   */
  protected function normalizeSharedPairs(mixed $rows): array {
    $pairs = [];
    foreach ((array) $rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $host = (string) ($row['host'] ?? '');
      $query = (string) ($row['query'] ?? '');
      if ($host !== '' && $query !== '') {
        $pairs[] = ['host' => $host, 'query' => $query];
      }
    }
    return $pairs;
  }

  /**
   * The configured shared-reference pairs, dropping incomplete rows.
   *
   * @return array<int, array{host: string, query: string}>
   *   The pairs.
   */
  protected function sharedReferencePairs(): array {
    return $this->normalizeSharedPairs($this->configuration['filter_shared'] ?? []);
  }

  /**
   * Resolves the ids of entities sharing references with the host entity.
   *
   * Resolved to an id set by its own queries rather than joined into the main
   * query, which is what makes the range correct. A condition on a multi-value
   * reference field joins the field table and returns one row per shared
   * target, and Query::finish() applies range() before Query::result()'s
   * fetchAllKeyed() collapses the duplicates — so a three-item listing whose
   * first hit shares three targets would render one card. Filtering on the id
   * key cannot multiply rows.
   *
   * Scoring rides along for free: one query per host target yields, per
   * candidate, how many of the host's targets it shares (depth) and how many
   * distinct pairs those targets span (breadth) — with no entity loads at all.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity.
   * @param string $entityTypeId
   *   The queried entity type.
   * @param string $bundle
   *   The queried bundle, or an empty string for all bundles.
   *
   * @return array|null
   *   NULL when no configured pair could contribute — there are no pairs, the
   *   host is unsaved, or every named host field is empty — in which case the
   *   caller adds no condition at all. Otherwise the matching ids in ranked
   *   order, possibly empty, meaning nothing matched.
   */
  protected function resolveSharedReferenceIds(ContentEntityInterface $entity, string $entityTypeId, string $bundle): ?array {
    $pairs = $this->sharedReferencePairs();
    if (!$pairs || $entity->isNew()) {
      return NULL;
    }
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    $storage = $this->entityTypeManager->getStorage($entityTypeId);

    // Read every host side first, so the per-target query budget is known
    // before any query runs.
    $sources = [];
    $targetCount = 0;
    foreach ($pairs as $index => $pair) {
      // Reading through MatcherReference keeps two-level keys working and
      // registers every traversed entity as a cache dependency, which is what
      // makes the listing rebuild when the host's own field is edited.
      $field = $this->matcherReference->getReferenceField(
        $entity,
        $pair['host'],
        $this->shape->getCacheableMetadata(),
      );
      $targetIds = $field ? array_column($field->getValue(), 'target_id') : [];
      $targetIds = array_values(array_unique(array_filter(
        $targetIds,
        static fn ($id) => $id !== NULL && $id !== '',
      )));
      if (!$targetIds) {
        // An empty host field contributes nothing — not even under AND, which
        // would otherwise turn "this page has not been tagged yet" into "show
        // nothing" instead of "show the plain list".
        continue;
      }
      $sources[] = [
        'pair' => $index,
        'field' => $this->conditionField($pair['query']),
        'targets' => $targetIds,
      ];
      $targetCount += count($targetIds);
    }
    if (!$sources) {
      return NULL;
    }

    // One query per target is exact but scales with the host's own tagging.
    // Past the limit, collapse to one query per pair: breadth still ranks,
    // depth degrades to 1 everywhere.
    $perTarget = $targetCount <= self::SHARED_FILTER_TARGET_LIMIT;

    $breadth = [];
    $depth = [];
    $pairHits = [];
    foreach ($sources as $source) {
      $batches = $perTarget
        ? array_map(static fn ($target) => [$target], $source['targets'])
        : [$source['targets']];
      $seenForPair = [];
      foreach ($batches as $batch) {
        $query = $storage->getQuery()
          // The main query is the access authority and is about to filter this
          // set anyway; repeating the check here only adds a second join.
          ->accessCheck(FALSE)
          ->condition($source['field'], $batch, 'IN');
        if ($bundle && $entityType->hasKey('bundle')) {
          $query->condition($entityType->getKey('bundle'), $bundle);
        }
        foreach ($query->execute() as $id) {
          $depth[$id] = ($depth[$id] ?? 0) + 1;
          $seenForPair[$id] = TRUE;
        }
      }
      foreach (array_keys($seenForPair) as $id) {
        $breadth[$id] = ($breadth[$id] ?? 0) + 1;
      }
      $pairHits[] = $seenForPair;
    }

    $ids = array_keys($breadth);
    if (($this->configuration['filter_shared_operator'] ?? 'or') === 'and') {
      // Every pair that could contribute must have matched. Pairs whose host
      // field was empty never became a source, so they do not veto.
      $required = count($pairHits);
      $ids = array_values(array_filter($ids, static fn ($id) => ($breadth[$id] ?? 0) === $required));
    }

    $this->sharedScores = [];
    foreach ($ids as $id) {
      $this->sharedScores[$id] = [
        'breadth' => $breadth[$id] ?? 0,
        'depth' => $depth[$id] ?? 0,
      ];
    }
    // Rank here so a capped id list keeps the strongest matches. Equal scores
    // keep their incoming order; the main query re-imposes the configured sort
    // on the survivors either way.
    usort($ids, fn ($a, $b) => [$this->sharedScores[$b]['breadth'], $this->sharedScores[$b]['depth']]
      <=> [$this->sharedScores[$a]['breadth'], $this->sharedScores[$a]['depth']]);

    return $ids;
  }

  /**
   * Whether results should be reordered by match strength.
   */
  protected function shouldRankBySharedReferences(): bool {
    return $this->sharedScores
      && !empty($this->configuration['filter_shared_rank'])
      && empty($this->configuration['paging']);
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
        $length = $this->getShape()->isIterable() ? (int) $this->configuration['length'] : 1;
        if ($lengthFilter = $this->configuration['length_filter']) {
          $filter = $this->shape->getComponent()->getFilter($lengthFilter);
          if ($filter->getPluginId() === 'number') {
            if ($filterValue = $filter->getProcessedValue()) {
              $length = (int) trim($filterValue);
            }
          }
        }
        $start = (int) $this->configuration['start'];

        // Resolved before the range is applied, because ranking replaces it:
        // a range cut by the configured sort would discard strong matches
        // before they were ever scored.
        $this->sharedScores = NULL;
        $sharedIds = $this->resolveSharedReferenceIds(
          $this->shape->getEntity(),
          $entityTypeId,
          (string) $this->configuration['bundle'],
        );
        $rankShared = $sharedIds !== NULL && $this->shouldRankBySharedReferences();
        if ($rankShared) {
          // Only the strongest candidates reach the main query, so the IN list
          // stays bounded on a large corpus. The multiplier leaves room for
          // rows the access check or the published filter will drop.
          $sharedIds = array_slice($sharedIds, 0, max(($start + $length) * 10, 50));
        }

        if ($this->getShape()->isIterable() && $this->configuration['paging']) {
          // A pager always needs a positive page size; "all results" is not a
          // meaningful page size, so fall back to the configured default.
          //
          // The element is chosen here rather than left to QueryBase::pager(),
          // which assigns the same getMaxPagerElementId() + 1 into a protected
          // property with no getter. A pager slot has to render THIS query's
          // element — a bare ['#type' => 'pager'] renders element 0, whoever
          // created it — so the id has to be knowable, and it is only 0 when
          // nothing else on the page paginated first.
          $element = $this->pagerManager->getMaxPagerElementId() + 1;
          $query->pager($length > 0 ? $length : 10, $element);
          $this->pagerElement = $element;
          // The rows themselves vary by page, whether or not a pager slot is
          // placed. Without this a render-cached listing serves page 1's rows
          // on page 2. '#type' => 'pager' bubbles the same context, but only
          // when a slot renders one — the rows must declare it on their own.
          $this->shape->addCacheableDependency(
            (new CacheableMetadata())->addCacheContexts(['url.query_args.pagers:' . $element])
          );
        }
        elseif ($rankShared) {
          // Ranking reorders everything the query returns, so the window has
          // to stay open until the scores have been applied. The slice happens
          // in getChildrenMatchEntities().
          $query->range(0, count($sharedIds) ?: 1);
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
        if ($sharedIds !== NULL) {
          // NULL means no configured pair could contribute — no pairs, an
          // unsaved host, or every named host field empty — and is the
          // deliberate degrade to the plain sorted list. An empty id set is a
          // different answer: everything was checked and nothing matched,
          // which has to return nothing rather than everything.
          $query->condition($entityType->getKey('id'), $sharedIds ?: [0], 'IN');
        }
        $entity = $this->shape->getEntity();
        if ($this->configuration['filter_entity'] && !$entity->isNew()) {
          $filterField = $this->conditionField($this->configuration['filter_entity']);

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
        if (!empty($this->configuration['filter_exclude_self'])
          && !$entity->isNew()
          && $entity->getEntityTypeId() === $entityTypeId) {
          // Guarded on the type: a different entity type shares no id space
          // with the host, so the condition would drop an unrelated entity
          // that happens to carry the same id.
          $query->condition($entityType->getKey('id'), $entity->id(), '<>');
        }
        $event = new ComponentValueEntityQueryEvent($this->getShape(), $query);
        $this->eventDispatcher->dispatch($event, ComponentValueEntityQueryEvent::EVENT_NAME);
        $this->entityQuery = $query;
        // Set a context for use by slots. The value stays a bare
        // QueryInterface — anything already reading this context is untouched.
        $this->shape->getComponent()->setPropShapeContext('entity_query', $this->getShape(), $query);
        if ($this->pagerElement !== NULL) {
          // A separate context, deliberately: it exists only when paging is
          // actually on, so a pager slot's option list is exactly the set of
          // props it can page, and a slot stranded by a provider swap finds
          // nothing to render instead of borrowing a foreign pager.
          //
          // @see \Drupal\neo_alchemist\Plugin\ComponentSlot\EntityQueryPagerSlot
          $this->shape->getComponent()->setPropShapeContext('entity_query_pager', $this->getShape(), $this->pagerElement);
        }
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
      $ids = array_values($ids);
      if ($this->shouldRankBySharedReferences()) {
        // Strongest match first, then the query's own order. usort is stable
        // in PHP 8, so equal scores keep the configured sort without needing a
        // third comparator.
        usort($ids, fn ($a, $b) => [$this->sharedScores[$b]['breadth'] ?? 0, $this->sharedScores[$b]['depth'] ?? 0]
          <=> [$this->sharedScores[$a]['breadth'] ?? 0, $this->sharedScores[$a]['depth'] ?? 0]);
        $length = $this->getShape()->isIterable() ? (int) $this->configuration['length'] : 1;
        $ids = array_slice($ids, (int) $this->configuration['start'], $length > 0 ? $length : NULL);
      }
      $storage = $this->entityTypeManager->getStorage($this->configuration['entity_type']);
      // loadMultiple() returns the entities in the order the ids were passed
      // (EntityStorageBase rebuilds the result from the flipped id list), so
      // the ranking above survives the load. EntityQueryValueTest pins it.
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
      // Pager element ids are request-scoped: PagerManager hands them out in
      // the order queries ask, so a woken plugin must recompute rather than
      // trust an id assigned during some earlier request.
      'pagerElement',
      // Scores belong to the query that produced them.
      'sharedScores',
    ]);
  }

}
