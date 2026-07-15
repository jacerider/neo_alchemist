<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_menu\Plugin\Menu\LocalAction;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Local action adding a mega menu region item from the menu edit form.
 *
 * Links to the menu's add-link form with ?neo_region=1 so the region
 * checkbox comes pre-checked, and appends a ?destination back to the menu
 * overview so saving returns the user there.
 */
final class AddMenuRegionAction extends LocalActionDefault {

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    $options = parent::getOptions($route_match);
    $options['query']['neo_region'] = 1;
    $options['query']['destination'] = Url::fromRouteMatch($route_match)->toString();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The destination is derived from the current route and its {menu}
    // parameter, so vary by route.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
