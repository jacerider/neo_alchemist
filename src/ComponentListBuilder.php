<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of components.
 */
final class ComponentListBuilder extends ConfigEntityListBuilder {

  use IconTrait;

  /**
   * {@inheritdoc}
   *
   * The listing groups rows by component group, and the filter and jump nav
   * both report counts across the whole library — all three would lie about a
   * paged subset. A component library is inherently bounded, so show it whole.
   */
  protected $limit = FALSE;

  /**
   * The sizes cache.
   *
   * @var array
   */
  protected array $sizes;

  /**
   * How many components wrap each source SDC, keyed by SDC id.
   *
   * @var array<string, int>
   */
  protected array $variantCounts;

  /**
   * Sub-group labels and sort keys collected while building rows.
   *
   * @var array<string, array>
   */
  protected array $subgroupLabels = [];

  /**
   * The component group plugin manager.
   *
   * @var \Drupal\neo_alchemist\ComponentGroupPluginManager
   */
  protected ComponentGroupPluginManager $componentGroupManager;

  /**
   * The component sub-group resolver.
   *
   * @var \Drupal\neo_alchemist\ComponentSubgroupResolver
   */
  protected ComponentSubgroupResolver $subgroupResolver;

  /**
   * The component usage service.
   *
   * @var \Drupal\neo_alchemist\ComponentUsage
   */
  protected ComponentUsage $componentUsage;

  /**
   * The component size plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $componentSizeManager;

  /**
   * {@inheritdoc}
   */
  protected const SORT_KEY = 'label';

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()));
    $instance->componentGroupManager = $container->get('plugin.manager.neo_component_group');
    $instance->subgroupResolver = $container->get('neo_alchemist.component_subgroup');
    $instance->componentUsage = $container->get('neo_alchemist.component_usage');
    $instance->componentSizeManager = $container->get('plugin.manager.neo_component_size');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    // Bucket the rows by group, and within a sub-grouped group by sub-group.
    // buildRow() smuggles the placement metadata on the "scope" cell — it is a
    // render-array property, so it never reaches the markup.
    $groups = [];
    foreach ($build['table']['#rows'] as $row) {
      $meta = $row['scope']['#meta'];
      unset($row['scope']['#meta']);
      $groups[$meta['group']][$meta['subgroup']['id']][] = [
        'data' => $row,
        'data-component-search' => $meta['search'],
      ];
    }

    $tables = [];
    $nav = [];
    foreach ($this->componentGroupManager->getDefinitions() as $id => $definition) {
      // PHP casts a numeric-looking array key to int, and this file is strict,
      // so a group id such as "123" would fatal on the string type hints below
      // rather than being coerced.
      $id = (string) $id;
      if (!isset($groups[$id])) {
        continue;
      }
      $groupAnchor = 'component-group-' . $id;
      $groupCount = array_sum(array_map('count', $groups[$id]));
      $nav[] = [
        'anchor' => $groupAnchor,
        'label' => $definition['label'],
        'count' => $groupCount,
        'child' => FALSE,
      ];

      if (!$this->subgroupResolver->hasSubgroups($id)) {
        $tables[$id] = $build['table'];
        $tables[$id]['#caption'] = $this->buildCaption($definition['label'], $definition['description']);
        $tables[$id]['#rows'] = reset($groups[$id]);
        $tables[$id]['#attributes']['id'] = $groupAnchor;
        $tables[$id]['#attributes']['class'][] = 'neo-alchemist-list-group';
        // Offsets the jump-nav target clear of the Neo toolbar.
        $tables[$id]['#attributes']['class'][] = 'scroll-mt-neo';
        continue;
      }

      // Sub-grouped: the group heading moves out of the table caption so each
      // sub-group can own a table of its own.
      $subgroups = [];
      foreach ($groups[$id] as $subgroupId => $rows) {
        $subgroups[$subgroupId] = $this->subgroupLabels[$subgroupId] ?? [
          'label' => $subgroupId,
          'sort' => [0, $subgroupId, ''],
        ];
        $subgroups[$subgroupId]['rows'] = $rows;
      }
      $subgroups = $this->subgroupResolver->sortSubgroups($subgroups);

      $tables[$id] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $groupAnchor,
          'class' => ['neo-alchemist-list-group', 'scroll-mt-neo'],
        ],
        'heading' => $this->buildCaption($definition['label'], $definition['description']),
        'subgroups' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => $groupAnchor,
            'class' => ['flex', 'flex-col', 'gap-4', 'py-4'],
          ],
        ],
      ];
      foreach ($subgroups as $subgroupId => $subgroup) {
        $subgroupAnchor = $groupAnchor . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $subgroupId));
        $nav[] = [
          'anchor' => $subgroupAnchor,
          'label' => $subgroup['label'],
          'count' => count($subgroup['rows']),
          'child' => TRUE,
        ];
        $table = $build['table'];
        $table['#rows'] = $subgroup['rows'];
        $table['#attributes']['class'][] = 'neo-alchemist-list-subgroup';
        $tables[$id]['subgroups'][$subgroupId]['#type'] = 'fieldset';
        $tables[$id]['subgroups'][$subgroupId]['#attributes']['id'] = $subgroupAnchor;
        $tables[$id]['subgroups'][$subgroupId]['#attributes']['class'][] = 'scroll-mt-neo';
        $tables[$id]['subgroups'][$subgroupId]['#title'] = $subgroup['label'];
        $tables[$id]['subgroups'][$subgroupId]['table'] = $table;
      }
    }

    $build['table'] = $tables;
    $build['filter'] = $this->buildFilter($nav);
    $build['filter']['#weight'] = -10;
    $build['#attached']['library'][] = 'neo_alchemist/component.list';
    $build['#cache']['tags'] = array_merge($build['#cache']['tags'] ?? [], $this->componentUsage->getCacheTags());
    return $build;
  }

  /**
   * Builds the sticky filter bar and group jump nav.
   *
   * @param array $nav
   *   The jump nav items.
   *
   * @return array
   *   The render array.
   */
  protected function buildFilter(array $nav): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['neo-alchemist-list-filter', 'card', 'p-3', 'mb-6'],
      ],
    ];
    $build['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter components'),
      '#title_display' => 'invisible',
      '#attributes' => [
        'class' => ['neo-alchemist-list-search'],
        'placeholder' => $this->t('Filter by name, machine name, source component or content type…'),
      ],
      '#neo_size' => 'sm',
    ];

    $build['nav'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-alchemist-list-nav', 'flex', 'flex-wrap', 'gap-1', 'mt-3']],
    ];
    foreach ($nav as $delta => $item) {
      $build['nav'][$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => Markup::create((string) $item['label'] . ' <span class="neo-alchemist-list-nav-count opacity-80">' . (int) $item['count'] . '</span>'),
        '#attributes' => [
          'href' => '#' . $item['anchor'],
          'data-nav-target' => $item['anchor'],
          'class' => [
            'neo-alchemist-list-nav-item',
            'badge',
            'px-2',
            $item['child'] ? 'bg-primary-600 text-primary-600-content' : 'bg-primary-500 text-primary-500-content',
          ],
        ],
      ];
    }

    $build['empty'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('No components match your filter.'),
      '#attributes' => [
        'class' => ['neo-alchemist-list-empty', 'mt-3', 'text-sm', 'hidden'],
      ],
    ];
    return $build;
  }

  /**
   * Builds a table caption.
   *
   * @param string|\Stringable $label
   *   The caption title. Group labels arrive as TranslatableMarkup.
   * @param string|\Stringable $description
   *   The optional caption description.
   *
   * @return array
   *   The render array.
   */
  protected function buildCaption(string|\Stringable $label, string|\Stringable $description = ''): array {
    $caption = [
      'title' => [
        '#markup' => '<div class="text-lg font-bold">' . $label . '</div>',
      ],
    ];
    if ((string) $description !== '') {
      $caption['description'] = [
        '#markup' => '<div class="text-xs">' . $description . '</div>',
      ];
    }
    return $caption;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['thumbnail'] = $this->t('Thumbnail');
    $header['label'] = $this->t('Label');
    $header['scope'] = $this->t('Scope');
    $header['size'] = $this->t('Size');
    $header['access'] = $this->t('Access');
    $header['used'] = $this->t('Used');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $row['thumbnail'] = ComponentManageHelper::buildThumbnailCell($entity, (string) $entity->label());

    $row['label']['data']['title']['#markup'] = $entity->label() . ' <small>(' . $entity->id() . ')</small>';
    if ($description = $entity->getDescription()) {
      $row['label']['data']['description']['#markup'] = '<div><small>' . $description . '</small></div>';
    }
    // The source SDC has been removed but the config entity remains. Flag it so
    // it can be recognised and deleted from the list.
    if (!$entity->getComponent()) {
      $row['label']['data']['missing']['#markup'] = '<div class="text-alert"><small>' . $this->adminIcon($this->t('Missing component: @id — the source component no longer exists. Delete this entry to clean it up.', ['@id' => $entity->getComponentId()]), 'exclamation-triangle')->render() . '</small></div>';
    }
    $row['label']['data']['source'] = $this->buildSourceLine($entity);

    $targetLabel = $this->subgroupResolver->getTargetLabel($entity);
    $subgroup = $this->subgroupResolver->resolve($entity) ?? ['id' => '', 'label' => '', 'sort' => [0, '', '']];
    // Remember the resolved labels so render() can caption each sub-group
    // without reloading every entity.
    if ($subgroup['id']) {
      $this->subgroupLabels[$subgroup['id']] = ['label' => $subgroup['label'], 'sort' => $subgroup['sort']];
    }
    $row['scope'] = [
      '#meta' => [
        'group' => $entity->getGroup(),
        'subgroup' => $subgroup,
        'search' => mb_strtolower(implode(' ', array_filter([
          $entity->label(),
          $entity->id(),
          $entity->getComponentId(),
          $this->subgroupResolver->getTargetTextLabel($entity),
          $entity->getGroupLabel(),
        ]))),
      ],
      '#neo_size' => 'min',
      '#neo_style' => 'xs',
      'data' => [
        '#markup' => $targetLabel,
      ],
    ];

    $row['size'] = [
      '#neo_size' => 'min',
      '#neo_style' => 'xs',
      'data' => [
        '#markup' => $entity->getSize() ? ($this->getSizesAsOptions()[$entity->getSize()] ?? $this->t('All')) : $this->t('All'),
      ],
    ];

    $row['access'] = [
      '#neo_size' => 'min',
      '#neo_align' => 'center',
      'data' => [],
    ];
    if ($instances = $entity->getAccessInstances()) {
      $labels = [];
      foreach ($instances as $instance) {
        $labels[] = $instance->label();
      }
      $row['access']['data']['#markup'] = '<div class="text-alert">' . $this->adminIcon($this->t('Limited access by:') . ' ' . implode(', ', $labels), 'lock')->iconOnly()->asTooltip() . '</div>';
    }
    else {
      $row['access']['data']['#markup'] = $this->adminIcon('Allow', 'check-circle')->iconOnly()->asTooltip();
    }

    $row['used'] = [
      '#neo_size' => 'min',
      '#neo_align' => 'center',
      'data' => $this->buildUsedCell($entity),
    ];
    $row['status'] = $this->statusIcon($entity->status())->iconOnly();
    return $row + parent::buildRow($entity);
  }

  /**
   * Builds the source component line of the label cell.
   *
   * Several components routinely wrap the same SDC (a "Market" variant and a
   * generic one), and nothing in the label says so. Name the source and, when
   * there is more than one wrapper, say how many — clicking it filters the
   * list down to the whole family.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $entity
   *   The component.
   *
   * @return array
   *   The render array.
   */
  protected function buildSourceLine(ComponentInterface $entity): array {
    $componentId = $entity->getComponentId();
    if (!$componentId) {
      return [];
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mt-1']],
      'source' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $componentId,
        '#attributes' => [
          'type' => 'button',
          'class' => [
            'neo-alchemist-list-source',
            'badge',
            'bg-base-300',
            'text-base-300-content',
            'px-2',
            'cursor-pointer',
          ],
          'data-component-filter' => $componentId,
          'title' => $this->t('Filter the list to every component built from @id.', ['@id' => $componentId]),
        ],
      ],
    ];
    $variants = $this->getVariantCounts()[$componentId] ?? 1;
    if ($variants > 1) {
      $build['variants'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('· @count variants', ['@count' => $variants]),
        '#attributes' => ['class' => ['text-2xs', 'ml-1', 'opacity-70']],
      ];
    }
    return $build;
  }

  /**
   * Builds the "Used" cell.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $entity
   *   The component.
   *
   * @return array
   *   The render array.
   */
  protected function buildUsedCell(ComponentInterface $entity): array {
    $count = $this->componentUsage->getCount((string) $entity->id());
    if (!$count) {
      return [
        '#markup' => '<span class="text-2xs opacity-60">' . $this->t('Unused') . '</span>',
      ];
    }
    return [
      '#type' => 'link',
      '#title' => $this->formatPlural($count, '1 place', '@count places'),
      '#url' => Url::fromRoute('entity.neo_component.usage', ['neo_component' => $entity->id()]),
      '#attributes' => ['class' => ['badge', 'bg-base-200', 'px-2']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $operations = parent::getDefaultOperations($entity);
    // An orphaned component (its source SDC was removed) cannot be customized,
    // edited or previewed without fatalling. Only allow deletion so the stale
    // config entity can be cleaned up.
    if (!$entity->getComponent()) {
      return array_intersect_key($operations, ['delete' => TRUE]);
    }
    return [
      'customize' => [
        'title' => $this->t('Customize'),
        'weight' => -10,
        'url' => $entity->toUrl(),
      ],
      'usage' => [
        'title' => $this->t('Usage'),
        'weight' => 15,
        'url' => $entity->toUrl('usage'),
      ],
      'clone' => [
        'title' => $this->t('Clone'),
        'weight' => 20,
        'url' => $entity->toUrl('clone-form'),
      ],
    ] + $operations;
  }

  /**
   * Get sizes as options.
   *
   * @return array
   *   The sizes options.
   */
  protected function getSizesAsOptions(): array {
    if (!isset($this->sizes)) {
      $this->sizes = array_map(function ($definition) {
        return $definition['label'];
      }, $this->componentSizeManager->getDefinitions());
    }
    return $this->sizes;
  }

  /**
   * Counts how many components wrap each source SDC.
   *
   * @return array<string, int>
   *   Keyed by SDC id.
   */
  protected function getVariantCounts(): array {
    if (!isset($this->variantCounts)) {
      $this->variantCounts = [];
      /** @var \Drupal\neo_alchemist\ComponentInterface $component */
      foreach ($this->getStorage()->loadMultiple() as $component) {
        if ($componentId = $component->getComponentId()) {
          $this->variantCounts[$componentId] = ($this->variantCounts[$componentId] ?? 0) + 1;
        }
      }
    }
    return $this->variantCounts;
  }

}
