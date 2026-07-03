<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
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
final class MenuValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

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
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MenuLinkTreeInterface $menu_link_tree,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->menuTree = $menu_link_tree;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'menu_id' => '',
    ];
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
  public function provideDefaultValue(mixed $value): mixed {
    $menu = $this->getMenu($this->configuration['menu_id']);
    if (!$menu) {
      return $value;
    }

    // Reset value.
    $value = [];
    // Cache based on the menu.
    $this->shape->addCacheableDependency($menu);

    $menu_id = $this->configuration['menu_id'];
    // Build the typical default set of menu tree parameters.
    $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_id);
    // Load the entire tree, not just the active trail, so that every item's
    // children are available for nested (dropdown) rendering.
    $parameters->expandedParents = [];

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
    $menu = $this->menuTree->build($tree);
    return $this->buildItems($menu['#items'] ?? []);
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
        // in_active_trail reflects the current route. Because the full tree is
        // loaded above (expandedParents reset), is_expanded is TRUE for any item
        // with children and is_collapsed is always FALSE — i.e. they describe the
        // rendered (fully expanded) tree rather than each link's "expanded" flag.
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
      $result[] = $entry;
    }
    return $result;
  }

}
