<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Traits;

/**
 * Reads the shape of an interface, for the tests that assert on it.
 *
 * Two tests pin the shape family's type structure — ShapeRoleInterfaceTest on
 * the roles the union is made of, ShapeSetupInterfaceTest on the one role it
 * deliberately is not — and both need the same two questions answered. Shared
 * so that the answers cannot drift apart: a subtle difference between two
 * copies (one sorting, the other not) is the kind of thing that makes one of
 * the two tests fail on a harmless reorder.
 *
 * @see \Drupal\Tests\neo_alchemist\Unit\ShapeRoleInterfaceTest
 * @see \Drupal\Tests\neo_alchemist\Unit\ShapeSetupInterfaceTest
 */
trait InterfaceReflectionTrait {

  /**
   * Method names declared by an interface itself, excluding what it inherits.
   *
   * @param string $interface
   *   The interface to reflect.
   *
   * @return string[]
   *   The method names, sorted.
   */
  private function ownMethods(string $interface): array {
    $names = [];
    foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() === $interface) {
        $names[] = $method->getName();
      }
    }
    sort($names);
    return $names;
  }

  /**
   * Every method name reachable through an interface, inherited included.
   *
   * @param string $interface
   *   The interface to reflect.
   *
   * @return string[]
   *   The method names, sorted.
   */
  private function allMethods(string $interface): array {
    $names = array_map(
      fn (\ReflectionMethod $method): string => $method->getName(),
      (new \ReflectionClass($interface))->getMethods(),
    );
    $names = array_values(array_unique($names));
    sort($names);
    return $names;
  }

  /**
   * The unqualified interface name, for readable failure messages.
   *
   * @param string $interface
   *   The interface to name.
   *
   * @return string
   *   The short name.
   */
  private function shortName(string $interface): string {
    return substr(strrchr($interface, '\\') ?: $interface, 1);
  }

}
