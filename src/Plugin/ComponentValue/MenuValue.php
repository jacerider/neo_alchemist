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
use Drupal\neo_alchemist\MatcherField;
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
  use ComponentValueModifierTrait;

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
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

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
    MatcherField $matcher_field
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->menuTree = $menu_link_tree;
    $this->matcherField = $matcher_field;
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
      $container->get('neo_alchemist.matcher_field')
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
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    $menu = $this->getMenu($this->configuration['menu_id']);
    if (!$menu) {
      return $value;
    }

    // Cache based on the menu.
    $this->shape->addCacheableDependency($menu);

    $menu_id = $this->configuration['menu_id'];
    // Build the typical default set of menu tree parameters.
    $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_id);

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

    if (!empty($menu['#items'])) {
      foreach ($menu['#items'] as $item) {
        /** @var \Drupal\Core\Url $url */
        $url = $item['url'];
        $icon = $url->getOptions('attributes')['attributes']['data-icon'] ?? '';
        $description = $url->getOptions('attributes')['attributes']['title'] ?? '';
        $value[] = [
          'title' => $item['title'],
          'description' => $description,
          'icon' => $icon,
          'url' => [
            'title' => $item['title'],
            'uri' => $item['url']->toString(),
          ],
        ];
      }
    }
    return $value;
  }

}
