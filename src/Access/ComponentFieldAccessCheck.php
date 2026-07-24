<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\Routing\Route;

/**
 * Provides a generic access checker for entities.
 */
class ComponentFieldAccessCheck implements AccessInterface {

  /**
   * Checks access to the entity operation on the given route.
   */
  public function access(Route $route, RouteMatchInterface $routeMatch, AccountInterface $account) {
    $requirement = $route->getRequirement('_neo_component_field');
    [$field, $operation] = explode('.', $requirement);
    $parameters = $routeMatch->getParameters();

    // A field has been specified, check if it is a valid field.
    $neoField = $parameters->get($field);
    if ($neoField instanceof ComponentTreeItem) {
      $fieldDefinition = $neoField->getFieldDefinition();
      if (!$neoField->belongsToFieldConfig() && !$fieldDefinition->allowCustom() && !$fieldDefinition->isHybrid()) {
        return AccessResult::forbidden();
      }
      return $neoField->access($operation, $account, TRUE);
    }

    return AccessResult::neutral();
  }

}
