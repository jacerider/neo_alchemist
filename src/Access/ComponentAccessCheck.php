<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides a generic access checker for entities.
 */
class ComponentAccessCheck implements AccessInterface {

  /**
   * Checks access to the entity operation on the given route.
   */
  public function access(Route $route, RouteMatchInterface $routeMatch, AccountInterface $account) {
    $requirement = $route->getRequirement('_neo_component');
    [$component, $operation] = explode('.', $requirement);
    $parameters = $routeMatch->getParameters();

    // A component has been specified, offload access check to component.
    $neoComponent = $parameters->get($component);
    if ($neoComponent instanceof ComponentInterface) {
      return $neoComponent->access($operation, $account, TRUE);
    }

    return AccessResult::neutral();
  }

}
