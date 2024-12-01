<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
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
    $requirement = $route->getRequirement('_neo_alchemist_field');
    [$entityTypeId, $bundle, $operation, $field, $component] = explode('.', $requirement . '....');
    $parameters = $routeMatch->getParameters();

    // A component has been specified, offload access check to component.
    $neoComponent = $parameters->get($component);
    if ($neoComponent instanceof ComponentInterface) {
      return $neoComponent->access($operation, $account, TRUE);
    }

    // A field has been specified, check if it is a valid field.
    $neoField = $parameters->get($field);
    if ($neoField instanceof ComponentTreeItem) {
      return $neoField->access($operation, $account, TRUE);
    }

    // Use entity type id and bundle to check if we have a component field.
    $entityTypeId = $parameters->get($entityTypeId);
    $bundle = $parameters->get($bundle);
    if (!$bundle && ($bundleTypeId = $route->getDefault('entity_bundle_type'))) {
      // If bundle is not set, try to get it from the entity bundle type.
      $bundle = $parameters->get($bundleTypeId);
      if ($bundle instanceof EntityInterface) {
        $bundle = $bundle->id();
      }
    }
    if ($entityTypeId && $bundle) {
      $fields = array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
      if ($fields) {
        return AccessResult::allowedIfHasPermission($account, 'administer ' . $entityTypeId . ' fields');
      }
    }
    return AccessResult::neutral();
  }

}
