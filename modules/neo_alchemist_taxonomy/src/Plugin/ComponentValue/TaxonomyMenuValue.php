<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_taxonomy\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist_taxonomy\TermLevelResolver;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Populates a menu prop from a taxonomy vocabulary's term hierarchy.
 *
 * The site builder picks a vocabulary (and optionally a root term to start from
 * plus a depth limit), and the vocabulary's nested, published, view-accessible
 * terms become the menu tree — mirroring the item shape produced by the core
 * "Menu" provider (\Drupal\neo_alchemist\Plugin\ComponentValue\MenuValue), so
 * any component consuming a `menu` prop renders it identically. Which term
 * field supplies each item's description and icon is configurable.
 */
#[ComponentValue(
  id: 'taxonomy_menu',
  label: new TranslatableMarkup('Taxonomy'),
  description: new TranslatableMarkup('Populate a menu from a taxonomy vocabulary’s term hierarchy.'),
  group: 'providers',
  ref_types: [
    'menu',
  ],
  weight: 10,
)]
final class TaxonomyMenuValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ComponentValueProducerInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * Term fields eligible to supply the per-item description.
   */
  protected const DESCRIPTION_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Term fields eligible to supply the per-item icon.
   */
  protected const ICON_FIELD_TYPES = [
    'neo_icon',
    'string',
  ];

  /**
   * Guards against pathological recursion when walking parents / branches.
   */
  protected const MAX_DEPTH = 50;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The term level resolver.
   *
   * @var \Drupal\neo_alchemist_taxonomy\TermLevelResolver
   */
  protected TermLevelResolver $levelResolver;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    RouteMatchInterface $route_match,
    ModuleHandlerInterface $module_handler,
    TermLevelResolver $level_resolver,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->routeMatch = $route_match;
    $this->moduleHandler = $module_handler;
    $this->levelResolver = $level_resolver;
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
      $container->get('entity_field.manager'),
      $container->get('current_route_match'),
      $container->get('module_handler'),
      $container->get('neo_alchemist_taxonomy.level_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'vocabulary' => '',
      // The term to start from (0 = whole vocabulary).
      'root' => 0,
      // Number of levels below the root to include (0 = unlimited).
      'depth' => 0,
      // Term fields mapped onto the per-item description / icon.
      'description_field' => '',
      'icon_field' => '',
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Matches MenuValue: the invented example links are editor scaffolding, so a
   * vocabulary (or subtree) that yields no terms renders nothing rather than
   * letting the seeded example ride through the now non-destructive search.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Get the available vocabulary options.
   *
   * @return array
   *   Vocabulary labels keyed by machine name, sorted by label.
   */
  protected function getVocabularyOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $vocabulary) {
      $options[$vocabulary->id()] = $vocabulary->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Load a vocabulary's term tree as lightweight rows.
   *
   * The lightweight rows (loadTree() with $load_entities = FALSE) carry ->tid,
   * ->name, ->depth and ->parents in hierarchical order — which is what the
   * configuration form (indented options, max depth) and provideDefaultValue()
   * (nesting by ->parents) both need. Passing TRUE would drop depth/parents.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param int $root
   *   The parent term ID to start from (0 = whole vocabulary).
   * @param int $depth
   *   The maximum depth below the root, or 0 for unlimited.
   *
   * @return object[]
   *   The tree rows, or an empty array when no vocabulary is given.
   */
  protected function loadTreeRows(string $vid, int $root = 0, int $depth = 0): array {
    if (!$vid) {
      return [];
    }
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    return $storage->loadTree($vid, $root, $depth ?: NULL, FALSE);
  }

  /**
   * Get the term fields of a vocabulary eligible for a mapping select.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param string[] $types
   *   The field types to include.
   *
   * @return array
   *   Field labels keyed by machine name, sorted by label.
   */
  protected function getFieldOptions(string $vid, array $types): array {
    $options = [];
    if (!$vid) {
      return $options;
    }
    foreach ($this->entityFieldManager->getFieldDefinitions('taxonomy_term', $vid) as $name => $definition) {
      if (in_array($definition->getType(), $types, TRUE)) {
        $options[$name] = $definition->getLabel() . ' (' . $name . ')';
      }
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    // The vocabulary drives the root / depth / field-mapping options, so those
    // controls are re-rendered by an AJAX rebuild of the "dependents" container
    // whenever the vocabulary changes.
    $depWrapperId = Html::getId(implode('-', $form['#parents']) . '-taxonomy-deps');
    $selectedVid = (string) ($form_state->getValue('vocabulary') ?? $this->configuration['vocabulary'] ?? '');

    $form['vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary'),
      '#description' => $this->t('The vocabulary whose term hierarchy becomes the menu.'),
      '#options' => $this->getVocabularyOptions(),
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $selectedVid,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => static::class . '::ajaxRefreshDependents',
        'wrapper' => $depWrapperId,
      ],
    ];

    $form['dependents'] = [
      '#type' => 'container',
      '#prefix' => '<div id="' . $depWrapperId . '">',
      '#suffix' => '</div>',
    ];

    if ($selectedVid) {
      $rows = $this->loadTreeRows($selectedVid);

      // Root: an indented list of the vocabulary's terms.
      $rootOptions = [];
      $maxDepth = 0;
      foreach ($rows as $row) {
        $rootOptions[(int) $row->tid] = str_repeat('- ', (int) $row->depth) . $row->name;
        $maxDepth = max($maxDepth, (int) $row->depth + 1);
      }
      $root = (int) ($this->configuration['root'] ?? 0);
      $form['dependents']['root'] = [
        '#type' => 'select',
        '#title' => $this->t('Root term'),
        '#description' => $this->t('Start the menu from this term. Leave empty to use the whole vocabulary.'),
        '#options' => $rootOptions,
        '#empty_option' => $this->t('- Whole vocabulary -'),
        '#default_value' => isset($rootOptions[$root]) ? $root : '',
      ];

      // Depth: how many levels below the root to include.
      $depthOptions = [0 => $this->t('Unlimited')];
      foreach (range(1, max($maxDepth, 1)) as $level) {
        $depthOptions[$level] = $level;
      }
      $form['dependents']['depth'] = [
        '#type' => 'select',
        '#title' => $this->t('Number of levels'),
        '#description' => $this->t('How many levels below the root to include.'),
        '#options' => $depthOptions,
        '#default_value' => (int) ($this->configuration['depth'] ?? 0),
      ];

      // Field mapping: which term field feeds the description / icon.
      $descriptionOptions = $this->getFieldOptions($selectedVid, self::DESCRIPTION_FIELD_TYPES);
      $form['dependents']['description_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Description field'),
        '#description' => $this->t('Term field mapped onto each item’s description.'),
        '#options' => $descriptionOptions,
        '#empty_option' => $this->t('- None -'),
        '#default_value' => isset($descriptionOptions[$this->configuration['description_field'] ?? '']) ? $this->configuration['description_field'] : '',
      ];

      $iconOptions = $this->getFieldOptions($selectedVid, self::ICON_FIELD_TYPES);
      $form['dependents']['icon_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Icon field'),
        '#description' => $this->t('Term field mapped onto each item’s icon.'),
        '#options' => $iconOptions,
        '#empty_option' => $this->t('- None -'),
        '#default_value' => isset($iconOptions[$this->configuration['icon_field'] ?? '']) ? $this->configuration['icon_field'] : '',
      ];
    }

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * AJAX callback: return the vocabulary-dependent controls container.
   */
  public static function ajaxRefreshDependents(array $form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    // The vocabulary select sits at [...'settings', 'vocabulary']; its sibling
    // "dependents" container holds the controls that depend on it.
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    $parents[] = 'dependents';
    return NestedArray::getValue($form, $parents) ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    // The vocabulary-dependent controls are grouped under a container for AJAX;
    // store them flat alongside "vocabulary" (matching defaultConfiguration()).
    if (isset($values['dependents']) && is_array($values['dependents'])) {
      $values += $values['dependents'];
      unset($values['dependents']);
    }
    $values['root'] = (int) ($values['root'] ?? 0);
    $values['depth'] = (int) ($values['depth'] ?? 0);
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $vid = (string) ($this->configuration['vocabulary'] ?? '');
    if (!$vid) {
      return $value;
    }
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    if (!$vocabulary) {
      return $value;
    }

    // Re-render when the vocabulary, its terms, or the active trail changes.
    $this->shape->addCacheableDependency($vocabulary);
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['taxonomy_term_list:' . $vid]);
    // in_active_trail below reflects the current page, so the value varies by
    // route (mirrors how MenuValue varies by the menu's active trail).
    $cache->addCacheContexts(['route']);
    $this->shape->addCacheableDependency($cache);

    $root = (int) ($this->configuration['root'] ?? 0);
    $depth = (int) ($this->configuration['depth'] ?? 0);
    $rows = $this->loadTreeRows($vid, $root, $depth);
    if (!$rows) {
      return [];
    }

    // Load the real term entities for label / URL / field access.
    $tids = array_map(static fn($row) => (int) $row->tid, $rows);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);

    // Group child term IDs under the first parent present in the loaded set;
    // terms whose parent is the requested root (or outside the depth window)
    // become the top-level items under key 0.
    $loaded = array_flip($tids);
    $childrenByParent = [];
    foreach ($rows as $row) {
      $tid = (int) $row->tid;
      $parentTid = 0;
      foreach ($row->parents as $candidate) {
        $candidate = (int) $candidate;
        if ($candidate && isset($loaded[$candidate])) {
          $parentTid = $candidate;
          break;
        }
      }
      $childrenByParent[$parentTid][] = $tid;
    }

    $activeTids = $this->getActiveTrailTids();
    return $this->buildBranch(0, $childrenByParent, $terms, $activeTids, []);
  }

  /**
   * Recursively build the menu value for the children of a parent term.
   *
   * @param int $parentTid
   *   The parent term ID whose children to build (0 = top level).
   * @param array $childrenByParent
   *   Child term IDs keyed by parent term ID.
   * @param \Drupal\taxonomy\TermInterface[] $terms
   *   The loaded term entities keyed by term ID.
   * @param array $activeTids
   *   The active-trail term IDs (as a lookup set: [tid => TRUE]).
   * @param array $path
   *   The term IDs already on the current branch (cycle guard).
   *
   * @return array
   *   A list of menu items, each with an optional nested `below` list.
   */
  private function buildBranch(int $parentTid, array $childrenByParent, array $terms, array $activeTids, array $path): array {
    if (count($path) >= self::MAX_DEPTH) {
      return [];
    }
    $result = [];
    foreach ($childrenByParent[$parentTid] ?? [] as $tid) {
      if (isset($path[$tid])) {
        continue;
      }
      $term = $terms[$tid] ?? NULL;
      if (!$term instanceof TermInterface || !$term->isPublished() || !$term->access('view')) {
        continue;
      }
      $this->shape->addCacheableDependency($term);

      $below = $this->buildBranch($tid, $childrenByParent, $terms, $activeTids, $path + [$tid => TRUE]);
      $url = $term->toUrl();
      $entry = [
        'title' => $term->label(),
        'description' => $this->fieldPlainText($term, (string) ($this->configuration['description_field'] ?? '')),
        'icon' => $this->fieldString($term, (string) ($this->configuration['icon_field'] ?? '')),
        'in_active_trail' => isset($activeTids[$tid]),
        'is_expanded' => !empty($below),
        'is_collapsed' => FALSE,
        'url' => [
          'title' => $term->label(),
          'uri' => $url->toUriString(),
          'options' => $url->getOptions(),
        ],
      ];
      if ($below) {
        $entry['below'] = $below;
      }
      // Let modules enrich or veto individual items (set $entry to NULL to drop
      // one), mirroring MenuValue's per-item alter. Cacheability belongs on
      // $this->shape.
      $this->moduleHandler->alter('neo_alchemist_taxonomy_menu_value_item', $entry, $term, $this->shape);
      if ($entry === NULL) {
        continue;
      }
      $result[] = $entry;
    }
    return $result;
  }

  /**
   * Get the active-trail term IDs for the current route.
   *
   * When the current page is a term canonical page, this is that term plus
   * each of its ancestors, walked by TermLevelResolver so the trail and the
   * level computation cannot disagree about what an ancestor is.
   *
   * @return array
   *   A lookup set of active term IDs ([tid => TRUE]); empty off a term page.
   */
  private function getActiveTrailTids(): array {
    if ($this->routeMatch->getRouteName() !== 'entity.taxonomy_term.canonical') {
      return [];
    }
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      $term = is_scalar($term)
        ? $this->entityTypeManager->getStorage('taxonomy_term')->load($term)
        : NULL;
    }
    if (!$term instanceof TermInterface) {
      return [];
    }
    return array_fill_keys($this->levelResolver->getAncestry($term), TRUE);
  }

  /**
   * Read a term field as trimmed plain text (for the description).
   */
  private function fieldPlainText(TermInterface $term, string $fieldName): string {
    if (!$fieldName || !$term->hasField($fieldName) || $term->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($term->get($fieldName)->value ?? '')));
  }

  /**
   * Read a term field's raw string value (for the icon name).
   */
  private function fieldString(TermInterface $term, string $fieldName): string {
    if (!$fieldName || !$term->hasField($fieldName) || $term->get($fieldName)->isEmpty()) {
      return '';
    }
    return (string) ($term->get($fieldName)->value ?? '');
  }

}
