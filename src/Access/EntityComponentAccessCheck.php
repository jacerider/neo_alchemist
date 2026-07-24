<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides a generic access checker for entities.
 */
class EntityComponentAccessCheck implements AccessInterface {

  /**
   * Checks if entity has alchemist field.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    $requirement = $route->getRequirement('_neo_entity_component');
    [$entity_type, $operation] = explode('.', $requirement);
    $parameters = $route_match->getParameters();
    if ($parameters->has($entity_type)) {
      $entity = $parameters->get($entity_type);
      foreach ($entity->getFieldDefinitions() as $fieldDefinition) {
        if ($fieldDefinition->getType() === 'neo_component_tree' && ($fieldDefinition->allowCustom() || $fieldDefinition->isHybrid())) {
          return $entity->access($operation, $account, TRUE);
        }
      }
    }
    return AccessResult::neutral();
  }

}
