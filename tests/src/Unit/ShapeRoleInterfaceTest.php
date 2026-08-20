<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Tests\neo_alchemist\Traits\InterfaceReflectionTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeExpansionInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeFieldItemInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeFieldMatchInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeFormInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeIdentityInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeLifecycleInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeOptionsInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeProvidersInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeRenderInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeSchemaInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeStateInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeTreeInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the shape's roles: what each promises, and that the union is only them.
 *
 * A caller holds the smallest role covering what it needs, so the compiler can
 * say when it reaches for the wrong one. That only holds while the roles stay
 * small and the big interface stays their union — both are asserted here rather
 * than left to review.
 */
#[Group('neo_alchemist')]
class ShapeRoleInterfaceTest extends UnitTestCase {

  use InterfaceReflectionTrait;

  /**
   * The roles the union is made of, and the caller need each one answers.
   *
   * Kept here rather than derived so that adding a role is a deliberate edit to
   * this list. Why the boundaries fall where they do is recorded once, on
   * ComponentShapePluginInterface.
   *
   * ComponentShapeSetupInterface is deliberately absent: it is the one role the
   * union does NOT extend, and half of what is asserted below would be wrong
   * for it — its methods return it, by design, so a chain of setters does not
   * widen halfway through. ShapeSetupInterfaceTest pins it instead.
   */
  private const ROLES = [
    ComponentShapeIdentityInterface::class => 'name this shape',
    ComponentShapeSchemaInterface::class => 'read the prop schema',
    ComponentShapeValueInterface::class => 'resolve a value',
    ComponentShapeRenderInterface::class => 'render a value',
    ComponentShapeFormInterface::class => 'build and process the prop form',
    ComponentShapeFieldMatchInterface::class => 'match an entity field to the prop',
    ComponentShapeFieldItemInterface::class => 'treat the prop as a field item',
    ComponentShapeTreeInterface::class => 'walk the shape tree',
    ComponentShapeExpansionInterface::class => 'ask what is expanded',
    ComponentShapeProvidersInterface::class => 'read the value providers',
    ComponentShapeContextInterface::class => 'ask what the shape is attached to',
    ComponentShapeStateInterface::class => 'ask active/required/editable/locked',
    ComponentShapeOptionsInterface::class => 'read the empty/default/access options',
    ComponentShapeLifecycleInterface::class => 'say whether it initialised, and receive the component events',
  ];

  /**
   * The ceiling a role may not grow through.
   *
   * The spec's bar is "no role grows back toward thirty". Depth is measured at
   * the interface, so a role passing this bound has failed even if the
   * implementation behind it shrank.
   */
  private const MAX_ROLE_METHODS = 12;

  /**
   * Every role is small enough to learn in one sitting.
   */
  public function testNoRoleGrowsBackTowardThirtyMethods(): void {
    foreach (self::ROLES as $role => $need) {
      $own = $this->ownMethods($role);
      $this->assertLessThanOrEqual(
        self::MAX_ROLE_METHODS,
        count($own),
        sprintf(
          '%s ("%s") declares %d methods, over the %d-method ceiling. Split it rather than raising the bound.',
          $this->shortName($role),
          $need,
          count($own),
          self::MAX_ROLE_METHODS,
        ),
      );
      $this->assertNotEmpty($own, $this->shortName($role) . ' declares nothing, so it is not a role.');
    }
  }

  /**
   * The big interface declares nothing of its own; it is only the union.
   *
   * This is what keeps every existing type hint working while callers narrow:
   * a method reachable through the union is reachable through exactly one role,
   * so moving a call site to that role loses nothing.
   */
  public function testTheBigInterfaceIsTheUnionOfItsRoles(): void {
    $this->assertSame(
      [],
      $this->ownMethods(ComponentShapePluginInterface::class),
      'ComponentShapePluginInterface declares methods of its own. A new shape method belongs on the role that answers the caller need it serves.',
    );

    $union = [];
    foreach (array_keys(self::ROLES) as $role) {
      $union = array_merge($union, $this->ownMethods($role));
    }
    sort($union);

    $reachable = $this->allMethods(ComponentShapePluginInterface::class);
    // The framework interfaces the union also extends are not roles.
    $framework = array_values(array_diff($reachable, $union));
    sort($framework);
    $this->assertSame(
      [
        'access',
        'addCacheableDependency',
        'getBaseId',
        'getCacheableMetadata',
        'getDerivativeId',
        'getPluginCollections',
        'getPluginDefinition',
        'getPluginId',
      ],
      $framework,
      'Everything reachable through ComponentShapePluginInterface is either a role method or one of the Drupal interfaces it extends.',
    );
  }

  /**
   * A method belongs to one role, so "which role covers this" has one answer.
   */
  public function testEachMethodBelongsToExactlyOneRole(): void {
    $seen = [];
    foreach (array_keys(self::ROLES) as $role) {
      foreach ($this->ownMethods($role) as $method) {
        $seen[$method][] = $this->shortName($role);
      }
    }
    $shared = array_filter($seen, fn (array $roles): bool => count($roles) > 1);
    $this->assertSame(
      [],
      array_map(fn (array $roles): string => implode(' + ', $roles), $shared),
      'These methods are declared by more than one role.',
    );
  }

  /**
   * A role mock is narrow, so a misspelled stub fails rather than nulls.
   *
   * This is the testability win the split exists for: the union doubles to
   * roughly a hundred methods, of which a test stubs one to five, and a typo on
   * a double that wide is silently NULL.
   */
  public function testRoleMockIsFarNarrowerThanUnionMock(): void {
    $union = count($this->allMethods(ComponentShapePluginInterface::class));
    $value = count($this->allMethods(ComponentShapeValueInterface::class));

    $this->assertGreaterThan(
      80,
      $union,
      'Guard on the comparison below still being worth making.',
    );
    $this->assertLessThan(
      20,
      $value,
      'A value-role double should be small enough that an unstubbed call is visible.',
    );
    $this->assertLessThan($union / 4, $value);
  }

  /**
   * A fluent shape method hands back the whole shape, never the role.
   *
   * Writing `: self` here would be the quiet way to break callers. In an
   * interface `self` binds to the *declaring* interface, so a `: self` on a
   * role turns a method that used to return the hundred-method union into one
   * returning a twelve-method role — `$shape->init()->getValue()` stops
   * type-checking, and because runtime is unaffected only an analyser notices.
   * Nineteen setters are declared this way, so state it as a rule.
   */
  public function testRoleMethodsReturnTheWholeShape(): void {
    $roles = array_keys(self::ROLES);
    // Reflection reports a `self` return as the literal string, not as the
    // interface it resolves to, so it has to be named to be caught.
    $banned = array_merge(['self'], $roles);
    foreach ($roles as $role) {
      foreach ($this->ownMethods($role) as $method) {
        $type = (new \ReflectionMethod($role, $method))->getReturnType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
          continue;
        }
        $this->assertNotContains(
          $type->getName(),
          $banned,
          sprintf(
            '%s::%s() returns %s. Return ComponentShapePluginInterface instead — a caller chaining off it expects a whole shape, and `self` on a role means the role.',
            $this->shortName($role),
            $method,
            $type->getName(),
          ),
        );
      }
    }
  }

  /**
   * Holding a role means holding something that can name itself.
   *
   * Identity is the one role every other extends. A caller that resolves a
   * value can say which prop it was resolving without widening to the union.
   */
  public function testEveryRoleCanNameTheShape(): void {
    foreach (array_keys(self::ROLES) as $role) {
      if ($role === ComponentShapeIdentityInterface::class) {
        continue;
      }
      $this->assertTrue(
        is_a($role, ComponentShapeIdentityInterface::class, TRUE),
        $this->shortName($role) . ' cannot name the shape it describes.',
      );
    }
  }

  /**
   * The union still satisfies every role, so existing type hints keep working.
   */
  public function testTheUnionSatisfiesEveryRole(): void {
    foreach (self::ROLES as $role => $need) {
      $this->assertTrue(
        is_a(ComponentShapePluginInterface::class, $role, TRUE),
        $this->shortName($role) . ' is not part of the union.',
      );
    }
  }

}
