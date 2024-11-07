<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides a generic access checker for entities.
 */
class FieldComponentAccessCheck implements AccessInterface {

  /**
   * Constructs a FieldComponentAccessCheck object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity Field Manager Service.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager
  ) {}

  /**
   * Checks access to the entity operation on the given route.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    $parameters = $route_match->getParameters();
    $requirement = $route->getRequirement('_neo_alchemist_field');
    $entityTypeId = $parameters->get('entity_type_id');
    if ($entityType = $parameters->get($requirement)) {
      $bundle = $entityType->id();
    }
    else {
      $bundle = $parameters->get('bundle') ?? $entityTypeId;
    }
    $fields = array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
    if ($fields) {
      return AccessResult::allowedIfHasPermission($account, 'administer ' . $entityTypeId . ' fields');
    }
    return AccessResult::neutral();
  }

}
