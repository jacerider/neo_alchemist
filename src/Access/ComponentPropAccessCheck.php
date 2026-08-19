<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Offloads an operation on one prop to that prop's shape.
 *
 * Requirement:
 * `_neo_component_prop: <component param>.<prop name param>.<operation>`.
 */
class ComponentPropAccessCheck extends ComponentRouteAccessCheckBase {

  /**
   * {@inheritdoc}
   */
  protected function requirement(): string {
    return '_neo_component_prop';
  }

  /**
   * {@inheritdoc}
   */
  protected function segments(): array {
    return [
      'component' => self::PARAM,
      'prop' => self::PARAM,
      'operation' => self::VALUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface {
    $component = $parts['component'];
    $prop = $parts['prop'];
    if (!$component instanceof ComponentInterface || !is_string($prop) || $prop === '') {
      return AccessResult::neutral();
    }
    // An aggregating component wraps its whole props schema in one synthetic
    // prop, which by construction is not in the schema's properties.
    if ($prop !== '_aggregate' && !isset($component->getComponentSchema()['properties'][$prop])) {
      return AccessResult::neutral();
    }
    return $component->getPropShape($prop)->access($parts['operation'], $account, TRUE);
  }

}
