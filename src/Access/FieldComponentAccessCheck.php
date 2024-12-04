<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
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
  public function access(Route $route, RouteMatchInterface $routeMatch, AccountInterface $account) {
    $requirement = $route->getRequirement('_neo_field_component');
    [$entityTypeId, $bundle, $operation] = explode('.', $requirement . '..');
    $parameters = $routeMatch->getParameters();
    $entityTypeId = $parameters->get($entityTypeId);
    $bundle = $parameters->get($bundle);
    if ($bundle instanceof EntityInterface) {
      $bundle = $bundle->id();
    }
    if ($entityTypeId && $bundle) {
      /** @var \Drupal\neo_alchemist\Entity\ComponentFieldConfig[] $fields */
      $fields = array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
      foreach ($fields as $field) {
        $access = $field->access($operation, $account, TRUE);
        if ($access->isAllowed()) {
          return $access;
        }
      }
    }
    return AccessResult::neutral();
  }

}
