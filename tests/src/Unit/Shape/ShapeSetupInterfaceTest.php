<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Shape;

use Drupal\Tests\neo_alchemist\Traits\InterfaceReflectionTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeSetupInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the one thing an initialised shape may not do: be set up again.
 *
 * Every other role is part of the union, so holding one narrows what you have
 * to learn. Setup is the role that narrows what you may *do*: it is the only
 * one the union does not extend, because a setter that ran too late used to be
 * a silent no-op in production and an assert() in development.
 *
 * These are reflection assertions rather than behaviour, on purpose. The
 * constraint this ticket moved into the type has no runtime behaviour left to
 * assert — that is the point of moving it — so what is worth pinning is the
 * shape of the type itself: which methods are on setup, that the union cannot
 * reach them, and that chaining does not quietly widen back.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapeSetupInterface
 * @see \Drupal\Tests\neo_alchemist\Unit\Shape\ShapeRoleInterfaceTest
 */
#[Group('neo_alchemist')]
class ShapeSetupInterfaceTest extends UnitTestCase {

  use InterfaceReflectionTrait;

  /**
   * What may only be done before init(), and why each one is too late after.
   *
   * Listed rather than derived so that adding a setter here is a deliberate
   * decision about the lifecycle, not a side effect of writing one. Sorted,
   * because the assertion below compares against sorted reflection output —
   * reordering the interface is not a behaviour change and must not fail.
   */
  private const SETUP_METHODS = [
    'addParentShape' => 'the shape id is chained from its parents, and options are keyed by id',
    'addPlugin' => 'init() collects the value collection it adds to',
    'allowInitPlugins' => 'it decides which plugins init() runs',
    'init' => 'the transition itself, and it is one-shot',
    'setDelta' => 'the shape id carries the delta, and options are keyed by id',
    'setOverrideValue' => 'init() reads it to overlay the field item',
    'setParentValue' => 'init() reads it to seed the field item',
  ];

  /**
   * The setters live on setup and nowhere else.
   */
  public function testSetupDeclaresTheSettersThatMustRunFirst(): void {
    $this->assertSame(
      array_keys(self::SETUP_METHODS),
      $this->ownMethods(ComponentShapeSetupInterface::class),
      'ComponentShapeSetupInterface declares something other than the pre-init lifecycle.',
    );
  }

  /**
   * The initialised shape cannot reach them, so a late call will not compile.
   *
   * This is the whole ticket. Before it, the constraint was eleven
   * `isInitialized()` guard sites — assertions, compiled out in production —
   * of which only a minority of the setters carried one, so violating the
   * order was a production-only wrong value rather than an error.
   */
  public function testTheInitialisedShapeDoesNotExposeThem(): void {
    $reachable = $this->allMethods(ComponentShapePluginInterface::class);

    foreach (self::SETUP_METHODS as $method => $why) {
      $this->assertNotContains(
        $method,
        $reachable,
        sprintf(
          'ComponentShapePluginInterface::%s() is reachable again. It must run before init(): %s.',
          $method,
          $why,
        ),
      );
    }
  }

  /**
   * Setting a shape up gives you every other role as well.
   *
   * The split is one-way: setup is a shape with more available, not a
   * different kind of thing. It is what keeps a shape under construction
   * passable to anything that takes a shape — the child bases hand one to
   * ChildOptionPolicy before init() and to addParentShape() after.
   */
  public function testSetupIsTheShapeWithMoreAvailable(): void {
    $this->assertTrue(
      is_a(ComponentShapeSetupInterface::class, ComponentShapePluginInterface::class, TRUE),
      'ComponentShapeSetupInterface no longer extends the union, so a shape under construction is not a shape.',
    );
    $this->assertFalse(
      is_a(ComponentShapePluginInterface::class, ComponentShapeSetupInterface::class, TRUE),
      'The union extends ComponentShapeSetupInterface, which hands the setters back to every existing type hint.',
    );
  }

  /**
   * Chaining stays in setup, and init() is the one call that leaves it.
   *
   * A setter returning the union would end the chain — `->setDelta(0)
   * ->setParentValue($v)` would stop type-checking after the first call.
   * init() returning the union is the opposite and is the point: it is the
   * handoff, and everything after it holds an initialised shape.
   *
   * `: static` would say this better and cannot be used. In an interface it
   * binds to the implementing class, and ComponentShapePluginBase plus some
   * twenty-five shape plugins declare these `: self` — which resolves to the
   * base, not to the subclass, and so is not a valid narrowing of `static`.
   */
  public function testSettersChainAndInitHandsOff(): void {
    foreach (array_keys(self::SETUP_METHODS) as $method) {
      $type = (new \ReflectionMethod(ComponentShapeSetupInterface::class, $method))->getReturnType();
      $this->assertInstanceOf(\ReflectionNamedType::class, $type, $method . '() declares no return type.');

      $expected = $method === 'init'
        ? ComponentShapePluginInterface::class
        : ComponentShapeSetupInterface::class;
      $this->assertSame(
        $expected,
        $type->getName(),
        sprintf(
          '%s() returns %s. A setter returns the setup interface so the chain continues; init() returns the union so it does not.',
          $method,
          $type->getName(),
        ),
      );
    }
  }

  /**
   * The base is still what the plugin manager hands back.
   *
   * Every shape plugin extends it, so this is what makes the manager's
   * narrowed return type — and every setup type hint below it — satisfiable.
   */
  public function testTheShapeBaseIsSetUpThroughThisInterface(): void {
    $this->assertTrue(
      is_a(ComponentShapePluginBase::class, ComponentShapeSetupInterface::class, TRUE),
      'ComponentShapePluginBase no longer implements ComponentShapeSetupInterface.',
    );
  }

}
