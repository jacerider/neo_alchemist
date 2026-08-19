<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Offloads an operation on a component to the component's own access handler.
 *
 * Requirement: `_neo_component: <component param>.<operation>`.
 */
class ComponentAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_component';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'component' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    if ($parts['component'] instanceof ComponentInterface) {
      return $parts['component']->access($parts['operation'], $account, TRUE);
    }
    return AccessResult::neutral();
  }

}
