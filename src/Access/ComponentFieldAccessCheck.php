<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Checks an operation on one component tree field item.
 *
 * Requirement: `_neo_component_field: <tree field item param>.<operation>`.
 */
class ComponentFieldAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_component_field';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'field' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    $item = $parts['field'];
    if (!$item instanceof ComponentTreeItem) {
      return AccessResult::neutral();
    }

    // Editing the shared layout itself (the field-config scope) is always
    // about the field, never about one entity. Per-entity editing is only
    // offered for the tree fields this entity actually applies: the helper
    // drops locked fields and then lets
    // hook_neo_alchemist_entity_component_fields_alter() drop the ones that do
    // not apply to this entity (e.g. a taxonomy term's non-matching hierarchy
    // levels), which otherwise authors content that never renders.
    if (!$item->belongsToFieldConfig()) {
      $entity = $item->getEntity();
      $applicable = $entity instanceof ContentEntityInterface
        ? neo_alchemist_entity_component_field_definitions($entity, TRUE)
        : [];
      if (!isset($applicable[$item->getFieldDefinition()->getName()])) {
        return AccessResult::forbidden();
      }
    }

    return $item->access($parts['operation'], $account, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheableDependencies(array $parts): iterable {
    // A field item is not cacheable in its own right, so name what the
    // decision was actually made from. Editing the shared layout is about the
    // field — and its prototype entity is unsaved, whose cache tag would be a
    // junk `<type>:` — while per-entity editing is about the entity: which
    // fields apply there is entity-specific (the alter hook), so both
    // outcomes have to be re-evaluated when the entity changes.
    $item = $parts['field'];
    if (!$item instanceof ComponentTreeItem) {
      return [];
    }
    return $item->belongsToFieldConfig()
      ? [$item->getFieldDefinition()]
      : [$item->getEntity()];
  }

}
