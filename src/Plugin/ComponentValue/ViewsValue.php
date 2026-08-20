<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeInterablePluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'views',
  label: new TranslatableMarkup('Views'),
  description: new TranslatableMarkup('Use the results of an entity-based view to provide values from the queried entity fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
  provider: 'views',
)]
final class ViewsValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchFieldSourceInterface {

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
   * The children-match mapper.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
   */
  protected ChildrenMatchMapper $childrenMatchMapper;

  /**
   * The view executable.
   *
   * @var \Drupal\views\ViewExecutable|null
   */
  protected ?ViewExecutable $view = NULL;

  /**
   * Whether the view lookup has run, regardless of whether it found one.
   *
   * @var bool
   */
  protected bool $viewResolved = FALSE;

  /**
   * Views currently executing, keyed "view_id:display_id".
   *
   * Static on purpose: the guard must span plugin instances, because each
   * nested resolution constructs a fresh ViewsValue.
   *
   * @var array<string, true>
   */
  protected static array $executingViews = [];

  /**
   * Whether any views value provider is currently executing its view.
   *
   * Lets serialization-triggered code paths (e.g. the metatag image token
   * walking a component tree) avoid initializing shapes mid-execution.
   */
  public static function isExecuting(): bool {
    return !empty(self::$executingViews);
  }

  /**
   * Search API indexes keyed by view base table. FALSE when there is none.
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface|false>
   */
  protected array $searchIndex = [];

  /**
   * Map of spl_object_id($entity) to the view result row index it came from.
   *
   * Rebuilt on every getChildrenMatchEntities() pass. Keyed by object id
   * because the delta the mapper hands to a field handler counts the entities
   * that SURVIVED filtering, not the rows.
   *
   * @var array<int, int>
   */
  protected array $viewRowIndex = [];

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
    ChildrenMatchMapper $children_match_mapper,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
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
      $container->get('neo_alchemist.children_match_mapper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view_id' => '',
      'view_display_id' => '',
      // Overrides for the entity type and bundle the view's rows resolve to.
      // Empty means "use whatever getViewEntityTypes() detects".
      'view_entity_type_id' => '',
      'view_entity_bundle' => '',
      'view_items_per_page' => $this->shape->getType() === ComponentShapePluginInterface::OBJECT ? 1 : NULL,
      'view_items_offset' => 0,
      'view_arguments' => [],
      'view_arguments_sort' => FALSE,
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
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $form = $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $wrapperId = $form['#id'];
    $viewId = $this->configuration['view_id'];

    $options = [];
    foreach (Views::getEnabledViews() as $view) {
      $options[$view->id()] = $view->label() . ' (' . $view->id() . ')';
    }
    asort($options);

    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $viewId,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($viewId) {
      $view = Views::getView($viewId);
      if (!$view) {
        return NULL;
      }
      $viewEntityTypes = $this->getViewEntityTypes($view);
      if (!$viewEntityTypes) {
        $form['markup'] = [
          '#type' => 'markup',
          '#markup' => $this->t('This view does not resolve to an entity type, so its results cannot be mapped to shape fields.'),
        ];
        return NULL;
      }
      $viewDisplayId = $this->configuration['view_display_id'];
      $displayOptions = [];
      foreach ($view->storage->get('display') as $display) {
        $displayOptions[$display['id']] = $display['display_title'];
      }

      $form['view_display_id'] = [
        '#type' => 'select',
        '#title' => $this->t('View Display'),
        '#options' => $displayOptions,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $viewDisplayId,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];

      if ($viewDisplayId) {
        $view->setDisplay($viewDisplayId);
        $display = $view->getDisplay();

        // The entity type can only be inferred for a Search API view, and an
        // index may carry several datasources, so expose the resolution rather
        // than guessing at it. Both selects are pre-filled and are disabled
        // when there is nothing to choose.
        $entityTypeOptions = array_map(fn($entityType) => $entityType->getLabel(), $viewEntityTypes);
        $viewEntityTypeId = $this->configuration['view_entity_type_id'] ?? '';
        if (!isset($entityTypeOptions[$viewEntityTypeId])) {
          $viewEntityTypeId = $this->getDefaultViewEntityTypeId($view, $viewEntityTypes);
        }
        $form['view_entity_type_id'] = [
          '#type' => 'select',
          '#title' => $this->t('Result entity type'),
          '#description' => $this->t('The entity type whose fields the mapping below offers. This scopes the mapping UI only — at render time every row resolves against its own entity, so mixed-type views work when the mapped sources are type-agnostic (<code>_entity:*</code>, <code>_view:*</code>); a real field simply resolves empty on rows of another type.'),
          '#options' => $entityTypeOptions,
          '#default_value' => $viewEntityTypeId,
          '#required' => TRUE,
          '#disabled' => count($entityTypeOptions) === 1,
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];

        $viewEntityType = $viewEntityTypes[$viewEntityTypeId];
        $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($viewEntityTypeId);
        $bundleOptions = [];
        foreach ($this->getViewEntityBundles($view, $viewEntityType) as $bundle) {
          $bundleOptions[$bundle] = $bundleInfo[$bundle]['label'] ?? $bundle;
        }
        $viewEntityBundle = $this->configuration['view_entity_bundle'] ?? '';
        if (!isset($bundleOptions[$viewEntityBundle])) {
          // Fall back to the sole candidate, preserving the behaviour of the
          // single-value bundle filter this replaces.
          $viewEntityBundle = count($bundleOptions) === 1 ? (string) array_key_first($bundleOptions) : '';
        }
        $form['view_entity_bundle'] = [
          '#type' => 'select',
          '#title' => $this->t('Result bundle'),
          '#description' => $this->t('Restricts the fields offered below. Leave empty to offer base fields only.'),
          '#options' => $bundleOptions,
          '#empty_option' => $this->t('- Any -'),
          '#default_value' => $viewEntityBundle,
          '#access' => (bool) $bundleOptions,
          '#disabled' => count($bundleOptions) === 1,
          '#ajax' => [
            'callback' => [static::class, 'refreshAjax'],
            'wrapper' => $wrapperId,
          ],
        ];

        if ($this->shape->getType() !== ComponentShapePluginInterface::OBJECT && $view->getDisplay()->usesPager()) {
          $form['view_items_per_page'] = [
            '#type' => 'number',
            '#title' => $this->t('Override items per page'),
            '#default_value' => $this->configuration['view_items_per_page'] ?? NULL,
            '#min' => 1,
          ];
          if ($this->shape instanceof ComponentShapeInterablePluginInterface) {
            $form['view_items_per_page']['#min'] = $this->shape->getMinItems() ?: 1;
            $form['view_items_per_page']['#max'] = $this->shape->getMaxItems() ?: NULL;
            $form['view_items_per_page']['#default_value'] = $this->configuration['view_items_per_page'] ?? $form['view_items_per_page']['#max'];
          }
        }

        $form['view_items_offset'] = [
          '#type' => 'number',
          '#title' => $this->t('Offset items'),
          '#default_value' => $this->configuration['view_items_offset'] ?? NULL,
          '#min' => 0,
        ];

        $arguments = $view->getHandlers('argument');
        if (!empty($arguments)) {
          $filters = $this->shape->getComponent()->getFilters();
          $options = [
            '_entity' => $this->t('- Component Entity -'),
          ];
          if ($this->shape->getTargetEntityType() === 'taxonomy_term') {
            $options['_taxonomy_term_children'] = $this->t('- Component Term and Children (argument must allow multiple values) -');
          }
          if ($filters) {
            $options = [
              'all' => $this->t('- Ignore -'),
            ] + $options + array_map(fn($filter) => $filter->label(), $filters);
            asort($options);
          }
          if ($options) {
            $form['view_arguments'] = [
              '#type' => 'fieldset',
              '#title' => $this->t('View Arguments'),
              '#access' => FALSE,
            ];
            $form['view_arguments_sort'] = [
              '#type' => 'checkbox',
              '#title' => $this->t('Sort results by argument values'),
              '#description' => $this->t('This is useful if your argument will be entity ids and you want the results to be sorted accordingly.'),
              '#default_value' => $this->configuration['view_arguments_sort'] ?? FALSE,
              '#access' => FALSE,
            ];
            foreach ($arguments as $id => $argument) {
              $handler = $display->getHandler('argument', $id);
              if ($handler) {
                $form['view_arguments']['#access'] = TRUE;
                $form['view_arguments_sort']['#access'] = TRUE;
                $form['view_arguments'][$id] = [
                  '#type' => 'select',
                  '#title' => $handler->adminLabel(),
                  '#options' => $options,
                  '#empty_option' => $this->t('- Select -'),
                  '#default_value' => $this->configuration['view_arguments'][$id] ?? NULL,
                ];
              }
            }
          }
        }

        // ViewsChildrenMatchHandler::addOptions() reads the executed view back
        // off the form state to list the columns the view itself renders.
        $form_state->set('view', $view);

        return new ChildrenMatchScope($viewEntityTypeId, $viewEntityBundle ?: NULL);
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchHandlers(): array {
    // The `_view:` handler needs this provider's executed view and its row
    // index to read a column back, so the provider owns it and registers it
    // into the mapper's handler map.
    return [new ViewsChildrenMatchHandler($this)];
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    if ($this->shape->getType() !== ComponentShapePluginInterface::OBJECT) {
      $itemsPerPage = $form_state->getValue('view_items_per_page');
      $form_state->setValue('view_items_per_page', $itemsPerPage ? (int) $itemsPerPage : NULL);
    }
    $offset = $form_state->getValue('view_items_offset');
    $form_state->setValue('view_items_offset', $offset ? (int) $offset : NULL);
    $form_state->setValue('view_entity_type_id', (string) $form_state->getValue('view_entity_type_id'));
    $form_state->setValue('view_entity_bundle', (string) $form_state->getValue('view_entity_bundle'));
    $form_state->setValue('view_arguments', array_filter($form_state->getValue('view_arguments', [])));
    $form_state->setValue('view_arguments_sort', !empty($form_state->getValue('view_arguments_sort')));
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
   * Gets the view executable.
   */
  protected function getView(): ?ViewExecutable {
    if (!$this->viewResolved) {
      // Set before the lookup, not after: $view stays NULL when the configured
      // view cannot be loaded, and a NULL-keyed guard would re-run
      // Views::getView() on every call for the rest of the request.
      $this->viewResolved = TRUE;
      $this->view = NULL;
      // Re-entrancy guard. Executing a view can serialize its row entities
      // (e.g. a result cache plugin), which computes fields like metatags,
      // whose tokens can walk a row entity's component tree — and when that
      // tree binds THIS view (a search page appearing in its own results),
      // shape init would execute it again, forever. A nested execution of the
      // same view/display resolves to no view instead; the outer execution's
      // results are unaffected.
      $key = ($this->configuration['view_id'] ?? '') . ':' . ($this->configuration['view_display_id'] ?? '');
      if (!empty(self::$executingViews[$key])) {
        return NULL;
      }
      if ($this->configuration['view_id'] && $this->configuration['view_display_id']) {
        self::$executingViews[$key] = TRUE;
        try {
          $this->doResolveView();
        }
        finally {
          unset(self::$executingViews[$key]);
        }
      }
    }
    return $this->view;
  }

  /**
   * Loads, configures, and executes the configured view.
   */
  protected function doResolveView(): void {
    $view = Views::getView($this->configuration['view_id']);
    if ($view) {
      $view->setDisplay($this->configuration['view_display_id']);
      // Add cache metadata from the display handler.
      $this->shape->addCacheableDependency($view->display_handler->getCacheMetadata());
      /** @var \Drupal\views\Plugin\views\cache\CachePluginBase $cache_plugin */
      $cache_plugin = $view->display_handler->getPlugin('cache');
      $cacheableMetadata = $this->shape->getCacheableMetadata();
      // Merge, never set: the shape's metadata is shared with every other
      // provider on it, and setCacheMaxAge() overwrites, so a permissive
      // view could raise a max-age another provider had lowered to 0.
      $cacheableMetadata->mergeCacheMaxAge($cache_plugin->getCacheMaxAge());
      $cacheableMetadata->addCacheTags($cache_plugin->getCacheTags());

      if ($this->configuration['view_items_per_page'] ?? NULL) {
        $view->setItemsPerPage($this->configuration['view_items_per_page']);
      }
      if ($this->configuration['view_items_offset'] ?? NULL) {
        $view->setOffset($this->configuration['view_items_offset']);
      }
      if ($this->configuration['view_arguments']) {
        $arguments = $view->getHandlers('argument');
        if ($arguments) {
          $args = [];
          foreach ($arguments as $id => $argument) {
            $argValue = NULL;
            $argKey = $this->configuration['view_arguments'][$id] ?? NULL;
            if ($argKey) {
              $argValue = 'all';
              switch ($argKey) {
                case '_entity':
                  $argValue = $this->shape->getEntity()->id();
                  break;

                case '_taxonomy_term_children':
                  $entity = $this->shape->getEntity();
                  $argValue = [
                    $entity->id(),
                  ];
                  /** @var \Drupal\taxonomy\TermStorageInterface $storage */
                  $storage = $this->entityTypeManager->getStorage('taxonomy_term');
                  foreach ($storage->loadTree($entity->bundle(), $entity->id()) as $term) {
                    $argValue[] = $term->tid;
                  }
                  $argValue = implode(',', $argValue);
                  break;

                default:
                  if ($filter = $this->shape->getComponent()->getFilter($argKey)) {
                    $argValue = $filter->getProcessedValue();
                  }
                  break;
              }
            }
            $args[] = $argValue;
          }
          $view->setArguments($args);
        }
      }
      $view->preExecute();
      $view->execute();
      $this->view = $view;
      // Set a context for use by slots.
      $this->shape->getComponent()->setPropShapeContext('views', $this->shape, $view);
    }
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
    $view = $this->getView();
    if (!$view) {
      return ChildrenMatchResult::unavailable();
    }
    // Collect the entities behind the rows, remembering which row each came
    // from. A row can legitimately carry no entity: a Search API index
    // returns a row per indexed item and only attaches "_entity" when the
    // item's original object is a loadable EntityAdapter.
    // @see \Drupal\search_api\Plugin\views\query\SearchApiQuery::addResults()
    $entities = [];
    $this->viewRowIndex = [];
    foreach ($view->result as $key => $row) {
      $entity = $row->_entity ?? NULL;
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      // StylePluginBase::renderFields() keys its output by the position of
      // the row within $view->result; ResultRow::$index mirrors that.
      $this->viewRowIndex[spl_object_id($entity)] = $row->index ?? $key;
      $entities[] = $entity;
    }

    $args = $view->args;
    if (!empty($this->configuration['view_arguments_sort']) && !empty($args[0])) {
      $ids = explode('+', $args[0]);
      if (count($ids) > 1) {
        $groupedEntities = [];
        foreach ($entities as $entity) {
          $groupedEntities[$entity->id()][] = $entity;
        }
        $orderedEntities = [];
        foreach ($ids as $id) {
          if (isset($groupedEntities[$id])) {
            $orderedEntities[$id] = $groupedEntities[$id];
          }
        }
        // Reset before re-appending: the ordered groups hold the SAME
        // objects already collected above, so appending them to the
        // untouched list emitted every entity twice.
        $entities = [];
        foreach ($orderedEntities as $entityGroup) {
          foreach ($entityGroup as $entity) {
            $entities[] = $entity;
          }
        }
      }
    }

    // A view that executed but returned no mappable row resolves to an empty
    // value, not to the per-child empty map — see ChildrenMatchResult.
    return $entities ? ChildrenMatchResult::of($entities) : ChildrenMatchResult::emptyValue();
  }

  /**
   * Reads a `_view:<field>` column for one iterated row.
   *
   * A column the view itself renders, which may not be a field on the row's
   * entity at all. Called by ViewsChildrenMatchHandler, which the provider
   * registers into the mapper's handler map; it lives here because reading the
   * column needs this provider's executed view and its row index.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField $field
   *   The child being filled for one entity.
   *
   * @return mixed
   *   The rendered column value, or NULL when the view or row is unavailable.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsChildrenMatchHandler
   */
  public function getViewRowFieldValue(ChildrenMatchField $field): mixed {
    $view = $this->getView();
    if (!$view || !isset($view->style_plugin)) {
      return NULL;
    }
    // The delta counts the entities that SURVIVED filtering, not the rows:
    // rows carrying no entity are dropped in getChildrenMatchEntities() and
    // unpublished entities are skipped inside the mapper, either of which
    // shifts it off the row it is meant to name. Resolve the row from the
    // entity instance instead, which is the exact object taken off
    // $view->result. The positional lookup remains as a fallback for callers
    // outside the mapping pass.
    $index = $this->viewRowIndex[spl_object_id($field->entity)] ?? ($view->result[$field->delta]->index ?? NULL);
    if ($index === NULL) {
      return NULL;
    }
    return $view->style_plugin->getField($index, substr($field->settings['field'], 6));
  }

  /**
   * Retrieves the entity type associated with the given view.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view entity for which to retrieve the entity type.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   The first entity type the view can return, or NULL if it returns none.
   */
  protected function getViewEntityType(ViewExecutable $view): ?EntityTypeInterface {
    $entityTypes = $this->getViewEntityTypes($view);
    return $entityTypes ? reset($entityTypes) : NULL;
  }

  /**
   * Retrieves the entity types the given view can return.
   *
   * Resolved in order of decreasing certainty:
   * 1. The view's base table IS an entity base or data table.
   * 2. The Views data for the base table declares an "entity type".
   * 3. The base table is a Search API index. An index declares no entity type
   *    of its own, only its datasources do, so resolve through those.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view entity for which to retrieve the entity types.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   *   Entity types keyed by entity type ID. Empty when the view is not backed
   *   by entities at all.
   */
  protected function getViewEntityTypes(ViewExecutable $view): array {
    $baseTable = $view->storage->get('base_table');

    foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
      if (in_array($baseTable, [
        $entityType->getBaseTable(),
        $entityType->getDataTable(),
      ], TRUE)) {
        return [$entityType->id() => $entityType];
      }
    }

    // Read the Views data directly rather than through
    // ViewExecutable::getBaseEntityType(), which returns FALSE on a miss and
    // throws PluginNotFoundException when the Views data names a stale entity
    // type.
    $entityTypeId = Views::viewsData()->get($baseTable)['table']['entity type'] ?? NULL;
    if ($entityTypeId && $this->entityTypeManager->hasDefinition($entityTypeId)) {
      return [$entityTypeId => $this->entityTypeManager->getDefinition($entityTypeId)];
    }

    // Search API index datasources. Non-entity datasources report NULL and are
    // simply not candidates: the rows they produce carry no "_entity" and are
    // skipped at runtime by provideDefaultValue().
    $entityTypes = [];
    if ($index = $this->getViewSearchIndex($view)) {
      /** @var \Drupal\search_api\IndexInterface $index */
      foreach ($index->getDatasources() as $datasource) {
        $id = $datasource->getEntityTypeId();
        if ($id && $this->entityTypeManager->hasDefinition($id)) {
          $entityTypes[$id] = $this->entityTypeManager->getDefinition($id);
        }
      }
    }
    return $entityTypes;
  }

  /**
   * Picks the entity type to preselect when none is stored yet.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its display already set.
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $entityTypes
   *   The candidate entity types, keyed by entity type ID.
   *
   * @return string
   *   The entity type ID to preselect.
   */
  protected function getDefaultViewEntityTypeId(ViewExecutable $view, array $entityTypes): string {
    // A display pinned to a single Search API datasource says which of a mixed
    // index's entity types it actually returns.
    if (count($entityTypes) > 1 && $this->getViewSearchIndex($view)) {
      /** @var \Drupal\views\Plugin\views\filter\FilterPluginBase $filter */
      foreach ($view->getDisplay()->getHandlers('filter') as $id => $filter) {
        if (($filter->options['field'] ?? $id) !== 'search_api_datasource') {
          continue;
        }
        $values = array_filter((array) ($filter->options['value'] ?? []));
        if (count($values) === 1) {
          $entityTypeId = substr((string) reset($values), strlen('entity:'));
          if (isset($entityTypes[$entityTypeId])) {
            return $entityTypeId;
          }
        }
      }
    }
    return (string) array_key_first($entityTypes);
  }

  /**
   * Retrieves the Search API index a view's base table belongs to, if any.
   *
   * Deliberately routed through the Views data rather than through Search
   * API's own classes: this plugin declares "views" as its only provider, so
   * search_api may not be installed. hook_views_data() records the index ID on
   * the base table definition, and the entity type manager answers "is
   * search_api installed" without a module handler dependency or a
   * class_exists() call whose result depends on classloader optimisation.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view to inspect.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The \Drupal\search_api\IndexInterface, or NULL when this is not a Search
   *   API view or search_api is not installed.
   *
   * @see \Drupal\search_api\Hook\SearchApiViewsHooks::viewsData()
   * @see \Drupal\search_api\Plugin\views\query\SearchApiQuery::getIndexFromTable()
   */
  protected function getViewSearchIndex(ViewExecutable $view): ?EntityInterface {
    $baseTable = $view->storage->get('base_table');
    if (!array_key_exists($baseTable, $this->searchIndex)) {
      $this->searchIndex[$baseTable] = FALSE;
      if ($this->entityTypeManager->hasDefinition('search_api_index')) {
        $indexId = Views::viewsData()->get($baseTable)['table']['base']['index'] ?? NULL;
        if ($indexId) {
          $this->searchIndex[$baseTable] = $this->entityTypeManager
            ->getStorage('search_api_index')
            ->load($indexId) ?: FALSE;
        }
      }
    }
    return $this->searchIndex[$baseTable] ?: NULL;
  }

  /**
   * Retrieves the bundles the given view's results can belong to.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its display already set.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type the results resolve to.
   *
   * @return string[]
   *   Bundle IDs, narrowed by the Search API datasource's own restriction and
   *   by a bundle filter on the display. Empty when the entity type has no
   *   bundle key.
   */
  protected function getViewEntityBundles(ViewExecutable $view, EntityTypeInterface $entityType): array {
    if (!$entityType->getKey('bundle')) {
      return [];
    }
    $entityTypeId = $entityType->id();
    $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo($entityTypeId));

    if ($index = $this->getViewSearchIndex($view)) {
      /** @var \Drupal\search_api\IndexInterface $index */
      // getDatasourceIfAvailable() rather than getDatasource(), which throws.
      $datasource = $index->getDatasourceIfAvailable('entity:' . $entityTypeId);
      if ($datasource) {
        $datasourceBundles = array_keys($datasource->getBundles());
        // ContentEntity::getBundles() falls back to a pseudo bundle named
        // after the entity type when the datasource restricts nothing. That is
        // not a real restriction, so ignore it.
        if ($datasourceBundles !== [$entityTypeId]) {
          $bundles = array_intersect($bundles, $datasourceBundles);
        }
      }
    }

    if ($filtered = $this->getViewBundleFilterValues($view, $entityType)) {
      $bundles = array_intersect($bundles, $filtered);
    }

    return array_values($bundles);
  }

  /**
   * Retrieves the bundles a display's bundle filter restricts results to.
   *
   * On a Search API view the filter names an arbitrary index field ID
   * ("node_type" for node's "type"), so it has to be resolved through the
   * index rather than compared to the bundle key directly.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its display already set.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entityType
   *   The entity type the results resolve to.
   *
   * @return string[]
   *   The allowed bundle IDs, or an empty array when the display does not
   *   usefully restrict them.
   */
  protected function getViewBundleFilterValues(ViewExecutable $view, EntityTypeInterface $entityType): array {
    $bundleKey = $entityType->getKey('bundle');
    if (!$bundleKey) {
      return [];
    }
    $datasourceId = 'entity:' . $entityType->id();
    $index = $this->getViewSearchIndex($view);

    /** @var \Drupal\views\Plugin\views\filter\FilterPluginBase $filter */
    foreach ($view->getDisplay()->getHandlers('filter') as $id => $filter) {
      $field = $filter->options['field'] ?? $id;
      if ($index) {
        /** @var \Drupal\search_api\IndexInterface $index */
        $indexField = $index->getField($field);
        // Filters placed on a datasource sub table use the raw property name,
        // so accept that when no index field matches.
        $isBundleFilter = $indexField
          ? ($indexField->getDatasourceId() === $datasourceId && $indexField->getPropertyPath() === $bundleKey)
          : ($field === $bundleKey);
      }
      else {
        $isBundleFilter = $field === $bundleKey;
      }
      if (!$isBundleFilter) {
        continue;
      }
      // A negated filter says what the bundle is NOT, which is unusable here.
      // "in" is InOperator's default (core bundle filters) and "or" is
      // ManyToOne's (search_api_options).
      if (!in_array(strtolower((string) ($filter->operator ?? '=')), ['in', 'or', '='], TRUE)) {
        continue;
      }
      if ($values = array_filter((array) ($filter->options['value'] ?? []))) {
        return array_values($values);
      }
    }
    return [];
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
    // All three are per-request caches rebuilt on demand. The executed view in
    // particular is expensive and pointless to carry across a serialize.
    return array_diff($this->traitSleep(), [
      'view',
      'viewResolved',
      'searchIndex',
      'viewRowIndex',
    ]);
  }

}
