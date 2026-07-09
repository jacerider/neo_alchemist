<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Plugin\Menu\LocalAction;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * "Add Alchemist block" local action that returns to the block layout page.
 *
 * When the action link is shown on the Block layout page it appends a
 * ?destination back to that same page, so creating a block returns the user
 * there instead of the Alchemist block collection.
 */
final class AddAlchemistBlockAction extends LocalActionDefault {

  /**
   * Routes on which a destination back to the current page is added.
   */
  private const BLOCK_LAYOUT_ROUTES = [
    'block.admin_display',
    'block.admin_display_theme',
  ];

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    $options = parent::getOptions($route_match);
    if (in_array($route_match->getRouteName(), self::BLOCK_LAYOUT_ROUTES, TRUE)) {
      // Return to the originating block layout page after the block is added.
      $options['query']['destination'] = Url::fromRouteMatch($route_match)->toString();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The destination is derived from the current route and its parameters
    // (e.g. the {theme} of block.admin_display_theme), so vary by route.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
