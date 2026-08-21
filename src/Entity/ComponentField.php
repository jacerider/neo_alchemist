<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * A component instance.
 */
final class ComponentField extends ComponentInstanceBase implements ComponentFieldInterface {

  /**
   * {@inheritdoc}
   */
  protected string $scope = 'field';

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $entityTypeId = $this->getTargetEntity()->getEntityTypeId();
    // The move op carries the direction as a path parameter and an optional
    // parent through the query. Generated through Url::fromRoute so path
    // processing applies — the same server-side generation the entity scope
    // gets, off the field-UI route family this scope (and the block scope,
    // which reuses this class) registers.
    if ($rel === 'move') {
      $direction = self::takeMoveDirection($options);
      return Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.move", [
        'neo_field' => ComponentFieldConfig::getKeyFromFieldname($this->getFieldItem()->getFieldDefinition()->getName()),
        'neo_component' => $this->uuid(),
        'direction' => $direction,
      ] + $this->getFieldDefinition()->getUrlParameters(), $options);
    }
    return match($rel) {
      'edit' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.edit", [
        'neo_field' => ComponentFieldConfig::getKeyFromFieldname($this->getFieldItem()->getFieldDefinition()->getName()),
        'neo_component' => $this->uuid(),
      ] + $this->getFieldDefinition()->getUrlParameters()),
      // Clone needs the component in its parameters, like edit and delete. The
      // field config's toUrl() carries only field-level rels, so a clone left
      // to the default arm fell through to a link template field_config has
      // not got and raised — the gap the op emission surfaced for the field-UI
      // and block scopes, both of which register a clone route.
      'clone' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.clone", [
        'neo_field' => ComponentFieldConfig::getKeyFromFieldname($this->getFieldItem()->getFieldDefinition()->getName()),
        'neo_component' => $this->uuid(),
      ] + $this->getFieldDefinition()->getUrlParameters()),
      'delete' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.delete", [
        'neo_field' => ComponentFieldConfig::getKeyFromFieldname($this->getFieldItem()->getFieldDefinition()->getName()),
        'neo_component' => $this->uuid(),
      ] + $this->getFieldDefinition()->getUrlParameters()),
      default => $this->getFieldDefinition()->toUrl($rel, $options),
    };
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE, ?ComponentShapePluginInterface $parentShape = NULL): bool|AccessResult {
    $targetEntity = $this->getTargetEntity();
    $targetEntityTypeId = $this->getTargetEntityTypeId();
    $targetEntityBundle = $this->getTargetEntityBundle();
    $account = $account ?? \Drupal::currentUser();
    $access = match(TRUE) {
      $operation === 'create' && $targetEntityTypeId && $targetEntityTypeId !== $targetEntity->getEntityTypeId() => AccessResult::forbidden('Invalid target entity type.'),
      $operation === 'create' && $targetEntityBundle && $targetEntityBundle !== $targetEntity->bundle() => AccessResult::forbidden('Invalid target entity bundle.'),
      default => AccessResult::allowedIfHasPermission($account, 'administer ' . $targetEntity->getEntityTypeId() . ' fields')
    };
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetPreviewEntity(): ?ContentEntityInterface {
    $entity = parent::getTargetPreviewEntity();

    // In certain cases, the entity that is set for preview is not the same
    // bundle as the one that is set in the field item. In this case, we cannot
    // use the configured preview entity.
    $itemEntity = $this->getFieldItem()->getEntity();
    if ($entity && ($entity->getEntityTypeId() !== $itemEntity->getEntityTypeId() || $entity->bundle() !== $itemEntity->bundle())) {
      $entityStorage = $this->entityTypeManager()->getStorage($itemEntity->getEntityTypeId());
      $query = $entityStorage->getQuery()
        ->accessCheck(TRUE)
        ->range(0, 1);
      $bundleKey = $entity->getEntityType()->getKey('bundle');
      if ($bundleKey) {
        $query->condition($bundleKey, $itemEntity->bundle());
      }
      $ids = $query->execute();
      if ($ids) {
        $entity = $entityStorage->load(reset($ids));
      }
    }

    return $entity;
  }

}
