<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\system\MenuInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'menu',
  label: new TranslatableMarkup('Menu'),
  description: new TranslatableMarkup('Use a menu to populate link fields.'),
  group: 'providers',
  ref_types: [
    'menu',
  ],
)]
final class MenuValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected MenuLinkTreeInterface $menuTree;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MenuLinkTreeInterface $menu_link_tree,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->menuTree = $menu_link_tree;
    $this->moduleHandler = $module_handler;
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
      $container->get('menu.link_tree'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'menu_id' => '',
      // Menu-level controls, mirroring
      // \Drupal\system\Plugin\Block\SystemMenuBlock.
      'level' => 1,
      'depth' => 0,
      // Default to the whole tree expanded: a component renders the menu
      // structurally (headers, footers, mega menus), not by the current page's
      // active trail. This preserves the provider's original behaviour.
      'expand_all_items' => TRUE,
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * A menu's schema examples are editor scaffolding — invented links that must
   * never reach a visitor. This provider always acts, so "resolved no links"
   * is an answer, not a decline: a start level deeper than the active trail,
   * or a tree whose every item failed access, means the prop renders nothing.
   * Since getDefaultValue() now keeps the value threaded past a non-claiming
   * producer that came up empty, only a claim expresses that, and this is the
   * mode that claims.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * Get a list of menus.
   *
   * @return \Drupal\system\MenuInterface[]
   *   An array of menu names keyed by their machine name.
   */
  protected function getMenus(): array {
    $menus = [];
    foreach ($this->entityTypeManager->getStorage('menu')->loadMultiple() as $menu) {
      assert($menu instanceof MenuInterface);
      $menus[$menu->id()] = $menu;
    }
    return $menus;
  }

  /**
   * Get a menu by its machine name.
   *
   * @param string $menuId
   *   The machine name of the menu.
   *
   * @return \Drupal\system\MenuInterface|null
   *   The menu entity, or NULL if not found.
   */
  protected function getMenu(string $menuId): ?MenuInterface {
    return $this->entityTypeManager->getStorage('menu')->load($menuId);
  }

  /**
   * Get a list of menu names.
   *
   * @return \Drupal\system\MenuInterface[]
   *   An array of menu names keyed by their machine name.
   */
  protected function getMenuOptions(): array {
    return array_map(static function (MenuInterface $menu) {
      return $menu->label();
    }, $this->getMenus());
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $menuId = $this->configuration['menu_id'];

    $options = $this->getMenuOptions();

    $form['menu_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Menu'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $menuId,
    ];

    // Menu-level controls, mirroring
    // \Drupal\system\Plugin\Block\SystemMenuBlock::blockForm(). They are
    // grouped under a details element for display and flattened back to the
    // top level in configurationMassage().
    $depthOptions = range(0, $this->menuTree->maxDepth());
    unset($depthOptions[0]);

    $form['menu_levels'] = [
      '#type' => 'details',
      '#title' => $this->t('Menu levels'),
      '#open' => ($this->configuration['level'] ?? 1) != 1 || ($this->configuration['depth'] ?? 0) != 0,
    ];
    $form['menu_levels']['level'] = [
      '#type' => 'select',
      '#title' => $this->t('Initial menu level'),
      '#default_value' => $this->configuration['level'] ?? 1,
      '#options' => $depthOptions,
      '#description' => $this->t('The menu will start rendering from this level. Use level 1 for the whole menu.'),
      '#required' => TRUE,
    ];
    $depthOptions[0] = $this->t('Unlimited');
    $form['menu_levels']['depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Number of levels to display'),
      '#default_value' => $this->configuration['depth'] ?? 0,
      '#options' => $depthOptions,
      '#description' => $this->t('This maximum number includes the initial level.'),
      '#required' => TRUE,
    ];
    $form['menu_levels']['expand_all_items'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expand all menu links'),
      '#default_value' => !empty($this->configuration['expand_all_items']),
      '#description' => $this->t('Render the whole menu tree expanded rather than only the current page’s trail. Recommended for structural placements such as headers and footers.'),
    ];

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    // The menu-level controls are grouped under a details element for display;
    // store them flat alongside menu_id (matching defaultConfiguration()).
    if (isset($values['menu_levels']) && is_array($values['menu_levels'])) {
      $values += $values['menu_levels'];
      unset($values['menu_levels']);
    }
    return $values;
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
  public function provideDefaultValue(mixed $value): mixed {
    $menu_id = $this->configuration['menu_id'];
    $menu = $this->getMenu($menu_id);
    if (!$menu) {
      return $value;
    }

    // Reset value.
    $value = [];
    // Cache based on the menu.
    $this->shape->addCacheableDependency($menu);

    $level = (int) ($this->configuration['level'] ?? 1);
    $depth = (int) ($this->configuration['depth'] ?? 0);
    $expandAll = !empty($this->configuration['expand_all_items']);

    // Build the default set of menu tree parameters. This seeds the active
    // trail, which is needed below when the initial level is greater than 1.
    $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_id);
    if ($expandAll) {
      // Load the entire tree, not just the active trail, so that every item's
      // children are available for nested (dropdown / multi-column) rendering.
      $parameters->expandedParents = [];
    }

    // Mirror \Drupal\system\Plugin\Block\SystemMenuBlock: the initial level
    // sets the minimum depth; the (relative) number of levels to display sets
    // the maximum depth (0 = unlimited, capped at the tree's maximum depth).
    $parameters->setMinDepth($level);
    if ($depth > 0) {
      $parameters->setMaxDepth(min($level + $depth - 1, $this->menuTree->maxDepth()));
    }

    // For an initial level greater than 1, show only the active trail's subtree
    // rooted at that level. If the current page has no ancestor at that level,
    // there is nothing to render.
    if ($level > 1) {
      if (count($parameters->activeTrail) >= $level) {
        // The active trail array is child-first; reverse it and take the parent
        // at the configured start level as the new root.
        $menu_trail_ids = array_reverse(array_values($parameters->activeTrail));
        $menu_root = $menu_trail_ids[$level - 1];
        $parameters->setRoot($menu_root)->setMinDepth(1);
        if ($depth > 0) {
          $parameters->setMaxDepth(min($level - 1 + $depth - 1, $this->menuTree->maxDepth()));
        }
      }
      else {
        return $value;
      }
    }

    // Load the tree based on this set of parameters.
    $tree = $this->menuTree->load($menu_id, $parameters);

    // Transform the tree using the manipulators you want.
    $manipulators = [
      // Only show links that are accessible for the current user.
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      // Use the default sorting of menu links.
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuTree->transform($tree, $manipulators);
    $build = $this->menuTree->build($tree);
    // The tree build carries the menu link cacheability (menu_link_content
    // entity tags, the route.menu_active_trails:{menu} cache context from
    // getCurrentRouteMenuTreeParameters(), access results); merge it into the
    // shape so menu edits invalidate components rendering this value.
    $this->shape->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
    return $this->buildItems($build['#items'] ?? []);
  }

  /**
   * Recursively map built menu render-array items to the menu value shape.
   *
   * @param array $items
   *   The `#items` (or a nested `below`) array from MenuLinkTree::build().
   *   Each item carries the same shape at every level (title, url, below), so
   *   nested children are processed with the same logic.
   *
   * @return array
   *   A list of menu items, each with an optional nested `below` list of the
   *   same shape.
   */
  private function buildItems(array $items): array {
    $result = [];
    foreach ($items as $item) {
      /** @var \Drupal\Core\Url $url */
      $url = $item['url'];
      $options = $url->getOptions();
      $attributes = $options['attributes'] ?? [];
      $entry = [
        'title' => $item['title'],
        'description' => $attributes['title'] ?? '',
        'icon' => $attributes['data-icon'] ?? '',
        // Drupal menu-state flags carried through from MenuLinkTree::build().
        // in_active_trail reflects the current route. Because the full tree
        // is loaded above (expandedParents reset), is_expanded is TRUE for
        // any item with children and is_collapsed is always FALSE — i.e. they
        // describe the rendered (fully expanded) tree rather than each link's
        // "expanded" flag.
        'in_active_trail' => !empty($item['in_active_trail']),
        'is_expanded' => !empty($item['is_expanded']),
        'is_collapsed' => !empty($item['is_collapsed']),
        'url' => [
          'title' => $item['title'],
          'uri' => $url->toUriString(),
          'options' => $options,
        ],
      ];
      if (!empty($item['below'])) {
        $entry['below'] = $this->buildItems($item['below']);
      }
      // Let modules enrich or veto individual items (set $entry to NULL to
      // drop one). Extra keys survive into the component prop (see the
      // in_active_trail flags above); cacheability belongs on $this->shape.
      $this->moduleHandler->alter('neo_alchemist_menu_value_item', $entry, $item, $this->shape);
      if ($entry === NULL) {
        continue;
      }
      $result[] = $entry;
    }
    return $result;
  }

}
