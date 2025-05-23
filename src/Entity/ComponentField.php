<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldInterface;

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
    return match($rel) {
      'edit' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.edit", [
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
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $targetEntity = $this->getTargetEntity();
    $targetEntityTypeId = $this->getTargetEntityTypeId();
    $targetEntityBundle = $this->getTargetEntityBundle();
    $account = $account ?? \Drupal::currentUser();
    $access = match(TRUE) {
      $operation === 'create' && $targetEntityTypeId && $targetEntityTypeId !== $targetEntity->getEntityTypeId() => AccessResult::forbidden('Invalid target entity type.'),
      $operation === 'create' && $targetEntityBundle && $targetEntityBundle !== $targetEntity->bundle() => AccessResult::forbidden('Invalid target entity bundle.'),
      default => AccessResult::allowedIfHasPermission($account, 'administer ' . $targetEntityTypeId . ' fields')
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
