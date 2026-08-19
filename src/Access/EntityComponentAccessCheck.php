<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Opens the per-entity Layout route for a host that has a field to edit.
 *
 * Requirement: `_neo_entity_component: <host entity param>.<operation>`.
 */
class EntityComponentAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_entity_component';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'entity' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    $entity = $parts['entity'];
    // Ask the same question EntityComponentController answers: which tree
    // fields does *this* entity actually offer for per-entity editing? A raw
    // field-definition scan skips
    // hook_neo_alchemist_entity_component_fields_alter(), so an entity whose
    // applicable field is locked (e.g. a taxonomy term at a level whose layout
    // flags no entity-customizable region) would be granted the Layout route
    // only to land on an empty "select the layout" table.
    return $entity instanceof ContentEntityInterface && neo_alchemist_entity_component_field_definitions($entity, TRUE)
      ? $entity->access($parts['operation'], $account, TRUE)
      : AccessResult::neutral();
  }

}
