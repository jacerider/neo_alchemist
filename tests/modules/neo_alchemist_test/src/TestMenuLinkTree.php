<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test;

use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;

/**
 * A menu link tree that returns a canned build, for testing MenuValue.
 *
 * MenuValue's interesting logic is everything AFTER the tree is built: the
 * recursive mapping of built items into the menu value shape, the item-alter
 * hook, and the cacheability it merges onto the shape. Standing up real
 * menu_link_content entities to reach that would drag in routes, an active
 * trail and access results — none of which the mapping cares about.
 *
 * So this stub lets a test hand MenuValue exactly the `#items` structure it
 * wants and then assert on the resolved prop. Swap it in with
 * $this->container->set('menu.link_tree', $stub).
 *
 * The parameters object is real, so the level/depth arithmetic MenuValue
 * performs against it (setMinDepth/setMaxDepth/setRoot, activeTrail) is
 * genuinely exercised — ::$capturedParameters records the result.
 */
class TestMenuLinkTree implements MenuLinkTreeInterface {

  /**
   * The build array to return from ::build().
   *
   * @var array
   */
  public array $build = [];

  /**
   * The active trail seeded into the returned tree parameters.
   *
   * @var array
   */
  public array $activeTrail = [];

  /**
   * The parameters MenuValue handed to ::load(), after its own adjustments.
   *
   * @var \Drupal\Core\Menu\MenuTreeParameters|null
   */
  public ?MenuTreeParameters $capturedParameters = NULL;

  /**
   * {@inheritdoc}
   */
  public function getCurrentRouteMenuTreeParameters($menu_name) {
    $parameters = new MenuTreeParameters();
    $parameters->activeTrail = $this->activeTrail;
    // Mirror the real service, which seeds expanded parents from the trail.
    $parameters->expandedParents = array_keys($this->activeTrail);
    return $parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function load($menu_name, MenuTreeParameters $parameters) {
    $this->capturedParameters = $parameters;
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function transform(array $tree, array $manipulators) {
    return $tree;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $tree) {
    return $this->build;
  }

  /**
   * {@inheritdoc}
   */
  public function maxDepth() {
    return 9;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubtreeHeight($id) {
    return 1;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpanded($menu_name, array $parents) {
    return $parents;
  }

}
