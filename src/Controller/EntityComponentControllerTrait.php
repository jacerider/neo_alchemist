<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * A trait for access the entity from route match.
 */
trait EntityComponentControllerTrait {

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $routeMatch) {
    $parameter_name = $routeMatch->getRouteObject()->getOption('_alchemist_entity_type_id');
    return $routeMatch->getParameter($parameter_name);
  }

}
