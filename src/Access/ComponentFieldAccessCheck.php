<?php

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
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
      // Editing the shared layout itself (the field-config scope) is always
      // about the field, never about one entity. Per-entity editing is only
      // offered for the tree fields this entity actually applies: the helper
      // drops locked fields and then lets
      // hook_neo_alchemist_entity_component_fields_alter() drop the ones that
      // do not apply to this entity (e.g. a taxonomy term's non-matching
      // hierarchy levels), which otherwise authors content that never renders.
      if (!$neoField->belongsToFieldConfig()) {
        $entity = $neoField->getEntity();
        $applicable = $entity instanceof ContentEntityInterface
          ? neo_alchemist_entity_component_field_definitions($entity, TRUE)
          : [];
        if (!isset($applicable[$neoField->getFieldDefinition()->getName()])) {
          return AccessResult::forbidden()->addCacheableDependency($entity);
        }
      }
      return $neoField->access($operation, $account, TRUE);
    }

    return AccessResult::neutral();
  }

}
