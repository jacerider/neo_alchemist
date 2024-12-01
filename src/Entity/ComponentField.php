<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
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

}
