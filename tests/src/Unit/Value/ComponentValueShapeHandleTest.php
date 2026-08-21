<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeIdentityInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeSchemaInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeTreeInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueShapeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the shape handle a ComponentValue plugin inherits (ticket 09).
 *
 * The shape was split into fourteen roles so a caller could accept the smallest
 * one covering what it uses; a producer could not take the offer, because both
 * the base's `$shape` property and the accessor were typed as the ~93-signature
 * union. These assertions pin the narrowed handle and the covariance that makes
 * "declare more" legal — the two invariants a future edit could quietly break.
 *
 * Reflection is used deliberately: whatever the analyser reads is the
 * contract, and a `self` / `static` return type reflects as the literal
 * string, not the class, so the accessor and every override names an explicit
 * interface.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValueShapeInterface
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::getShape()
 */
#[Group('neo_alchemist')]
class ComponentValueShapeHandleTest extends UnitTestCase {

  /**
   * The accessor's declared return type is the narrow handle, not the union.
   *
   * This is the breaking change recorded for ticket 10: any external caller or
   * implementer of getShape() now sees ComponentValueShapeInterface.
   */
  public function testAccessorReturnsTheHandle(): void {
    $method = new \ReflectionMethod(ComponentValuePluginInterface::class, 'getShape');
    $type = $method->getReturnType();
    $this->assertInstanceOf(\ReflectionNamedType::class, $type);
    $this->assertSame(ComponentValueShapeInterface::class, $type->getName());
  }

  /**
   * The handle is Context + Value + cacheability, and Identity comes free.
   */
  public function testHandleCarriesTheOwedRoles(): void {
    foreach ([
      ComponentShapeContextInterface::class,
      ComponentShapeValueInterface::class,
      CacheableResponseInterface::class,
      // Extended transitively by both Context and Value.
      ComponentShapeIdentityInterface::class,
    ] as $owed) {
      $this->assertTrue(
        is_a(ComponentValueShapeInterface::class, $owed, TRUE),
        sprintf('The producer handle must carry %s.', $owed),
      );
    }
  }

  /**
   * The handle is narrow: roles a producer must declare are NOT on it.
   *
   * If a role a producer reaches only sometimes (schema, tree…) leaked into the
   * handle, reaching it would stop being the static error this ticket exists to
   * make it, and "no role grows to fit the callers" would be violated silently.
   */
  public function testHandleExcludesTheDeclaredRoles(): void {
    foreach ([
      ComponentShapeSchemaInterface::class,
      ComponentShapeTreeInterface::class,
    ] as $notOwed) {
      $this->assertFalse(
        is_a(ComponentValueShapeInterface::class, $notOwed, TRUE),
        sprintf('%s is a per-plugin "declare more" role and must stay off the handle.', $notOwed),
      );
    }
  }

  /**
   * The union is a subtype of the handle, so widening runs the one legal way.
   *
   * Two things rest on this: the value base may hold a full shape in a property
   * typed as the handle, and a producer may override getShape() to return the
   * union (a covariant narrowing). Were it false, both would be runtime errors.
   */
  public function testUnionIsSubtypeOfTheHandle(): void {
    $this->assertTrue(
      is_a(ComponentShapePluginInterface::class, ComponentValueShapeInterface::class, TRUE),
      'The shape union must extend the producer handle, or a full shape could not satisfy the narrowed $shape property.',
    );
  }

  /**
   * No shipped plugin widens the handle in a getShape() override.
   *
   * Every override must return a subtype of the handle (in practice the union),
   * named explicitly — never `self`/`static` (which reflects as a string) and
   * never a type broader than the handle. This is the reflective invariant that
   * keeps the covariance honest across the whole plugin set as it grows.
   */
  public function testShippedOverridesNarrowNeverWiden(): void {
    $offenders = [];
    foreach ($this->shippedValueClassesDeclaringGetShape() as $class) {
      $type = (new \ReflectionMethod($class, 'getShape'))->getReturnType();
      $name = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
      if (in_array($name, ['self', 'static'], TRUE) || !is_a($name, ComponentValueShapeInterface::class, TRUE)) {
        $offenders[$class] = $name;
      }
    }
    $this->assertSame([], $offenders, 'These getShape() overrides return a type that is not an explicit subtype of the handle.');
  }

  /**
   * Every ComponentValue class that declares its own getShape().
   *
   * Discovered from the filesystem so a new override is covered without editing
   * this test. Traits and unloadable classes are skipped by design.
   *
   * @return string[]
   *   Fully-qualified class names.
   */
  protected function shippedValueClassesDeclaringGetShape(): array {
    $classes = [];
    $dir = __DIR__ . '/../../../../src/Plugin/ComponentValue';
    foreach (glob($dir . '/*.php') ?: [] as $file) {
      $class = 'Drupal\\neo_alchemist\\Plugin\\ComponentValue\\' . basename($file, '.php');
      // Skip traits and handler classes that are not value plugins at all.
      if (!class_exists($class) || !method_exists($class, 'getShape')) {
        continue;
      }
      if ((new \ReflectionMethod($class, 'getShape'))->getDeclaringClass()->getName() === $class) {
        $classes[] = $class;
      }
    }
    return $classes;
  }

}
