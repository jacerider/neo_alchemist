<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Opens the Field-UI layout route for a bundle carrying a tree field.
 *
 * Requirement:
 * `_neo_field_component: <entity type id param>.<bundle param>.<operation>`.
 */
class FieldComponentAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * Constructs a FieldComponentAccessCheck object.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity Field Manager Service.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_field_component';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'entity_type_id' => self::PARAM,
      'bundle' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    $entityTypeId = $parts['entity_type_id'];
    $bundle = $parts['bundle'];
    // Field UI routes carry the bundle either as its own entity or as a
    // plain id, depending on whether the entity type has a bundle entity.
    if ($bundle instanceof EntityInterface) {
      $bundle = $bundle->id();
    }
    if (!$entityTypeId || !$bundle) {
      return AccessResult::neutral();
    }

    $fields = array_filter(
      $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle),
      static fn ($field) => $field->getType() === 'neo_component_tree'
    );
    foreach ($fields as $field) {
      $access = $field->access($parts['operation'], $account, TRUE);
      if ($access->isAllowed()) {
        return $access;
      }
    }
    return AccessResult::neutral();
  }

}
