<?php

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the neo_component entity type.
 *
 * @see \Drupal\neo_alchemist\Entity\Component
 */
class ComponentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected $viewLabelOperation = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    // ksm('access check', $operation, $entity->getTargetEntityTypeId(), $entity->getTargetEntity()->getEntityTypeId());
    return parent::checkAccess($entity, $operation, $account);
  }

  // /**
  //  * {@inheritdoc}
  //  */
  // protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
  //   ksm('access create check', $entity_bundle, $context);
  //   return parent::checkCreateAccess($account, $context, $entity_bundle);
  // }

}
