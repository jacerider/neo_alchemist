<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
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
      // Ask the same question EntityComponentController answers: which tree
      // fields does *this* entity actually offer for per-entity editing? A raw
      // field-definition scan skips
      // hook_neo_alchemist_entity_component_fields_alter(), so an entity whose
      // applicable field is locked (e.g. a taxonomy term at a level whose
      // layout flags no entity-customizable region) would be granted the Layout
      // route only to land on an empty "select the layout" table.
      $access = $entity instanceof ContentEntityInterface && neo_alchemist_entity_component_field_definitions($entity, TRUE)
        ? $entity->access($operation, $account, TRUE)
        : AccessResult::neutral();
      // Which fields apply can be entity-specific (the alter hook), so both
      // outcomes have to be re-evaluated when the entity changes.
      if ($access instanceof RefinableCacheableDependencyInterface) {
        $access->addCacheableDependency($entity);
      }
      return $access;
    }
    return AccessResult::neutral();
  }

}
