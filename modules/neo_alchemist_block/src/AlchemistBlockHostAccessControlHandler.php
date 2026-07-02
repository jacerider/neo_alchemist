<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist_block\Entity\AlchemistBlock;

/**
 * Access control handler for the Alchemist block host entity type.
 *
 * The 'view' operation drives whether the components of an Alchemist block
 * render for site visitors, so it only depends on the block config entity
 * being enabled. All other operations are editorial and require the component
 * management permission.
 */
final class AlchemistBlockHostAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $block = AlchemistBlock::load($entity->bundle());
    if (!$block) {
      return AccessResult::forbidden('The Alchemist block no longer exists.');
    }
    if ($operation === 'view') {
      return AccessResult::allowedIf($block->status())->addCacheableDependency($block);
    }
    return AccessResult::allowedIfHasPermission($account, 'administer neo_alchemist_block_host fields')->addCacheableDependency($block);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'administer neo_alchemist_block_host fields');
  }

}
