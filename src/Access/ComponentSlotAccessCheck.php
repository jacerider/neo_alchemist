<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Allows a route naming a slot the component actually declares.
 *
 * Requirement:
 * `_neo_component_slot: <component param>.<slot name param>.<operation>`.
 *
 * The operation is declared because every route writes one, but this checker
 * does not read it: a slot the component declares is manageable, and the
 * component's own access handler decides who may manage it.
 */
class ComponentSlotAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_component_slot';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'component' => self::PARAM,
      'slot' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    $component = $parts['component'];
    $slot = $parts['slot'];
    if ($component instanceof ComponentInterface && is_string($slot) && isset($component->getComponentSlots()[$slot])) {
      return AccessResult::allowed();
    }
    return AccessResult::neutral();
  }

}
