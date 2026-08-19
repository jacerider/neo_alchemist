<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeContextInterface;
use Drupal\neo_alchemist\ComponentShapeIdentityInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentShapeValueInterface;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MethodCannotBeConfiguredException;

/**
 * Pins the shape double: narrow to configure, wide enough to hand over.
 *
 * The roles exist so a test can double five methods instead of a hundred, and
 * a value provider's constructor still demands the whole union — so a role
 * double cannot be passed to one. ShapeDoubleTrait is the join: the test
 * configures a role, and a union-typed double forwards that role's methods to
 * it.
 *
 * What that buys is asserted here rather than assumed, because the buy is the
 * only reason the indirection is worth having: a name that is not on the
 * declared role cannot be stubbed at all. On the union it could — there are a
 * hundred names to hit by accident, ::testMethodOffTheRoleCannotBeStubbed()
 * uses a real one — and the test would go on passing against a NULL.
 *
 * @see \Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait
 */
#[Group('neo_alchemist')]
class ShapeDoubleTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * The double can go anywhere the union is required.
   *
   * Which is the whole reason it exists: ComponentValuePluginBase types its
   * shape as ComponentShapePluginInterface, so a bare role double is rejected
   * by every value provider's constructor.
   */
  public function testTheDoubleSatisfiesTheUnion(): void {
    $shape = $this->shapeDouble([$this->shapeRole(ComponentShapeContextInterface::class)]);

    $this->assertInstanceOf(ComponentShapePluginInterface::class, $shape);
  }

  /**
   * What the role was told to answer is what the double answers.
   */
  public function testTheDeclaredRoleAnswers(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getScope')->willReturn('field');

    $shape = $this->shapeDouble([$context]);

    $this->assertSame('field', $shape->getScope());
  }

  /**
   * The role may be configured after the double is built.
   *
   * Forwarding resolves at call time, so a test can build the double once in a
   * helper and vary what it answers per case.
   */
  public function testTheRoleMayBeConfiguredAfterTheDoubleIsBuilt(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $shape = $this->shapeDouble([$context]);

    $context->method('getScope')->willReturn('config');

    $this->assertSame('config', $shape->getScope());
  }

  /**
   * What ::shapeRole() hands back is the role, not a shape.
   *
   * The two rejection tests below have to build their double with
   * createStub(), for the teardown reason recorded on the first of them — so
   * neither of them would notice ::shapeRole() quietly widening to a union
   * double, and the ticket's whole acceptance criterion rests on it not doing
   * that. This is the assertion that closes the gap.
   */
  public function testTheRoleDoubleIsNotTheWholeShape(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);

    $this->assertInstanceOf(ComponentShapeContextInterface::class, $context);
    $this->assertNotInstanceOf(
      ComponentShapePluginInterface::class,
      $context,
      'A role double that satisfies the union is a hundred methods wide, and everything below stops meaning anything.',
    );

    // Declared so the every-role-is-declared guard has nothing to say; the
    // narrow thing above is the same thing a shape double forwards to.
    $this->shapeDouble([$context]);
  }

  /**
   * A misspelled stub fails instead of silently returning NULL.
   *
   * The acceptance criterion in the ticket, pinned so it cannot rot.
   *
   * A stub stands in for ::shapeRole()'s mock in this one and the next: the
   * rejection happens after expects() has already registered a matcher, so the
   * double is left holding one with no method name on it — and PHPUnit
   * verifies mocks at teardown but not stubs. Narrowness is a property of the
   * type, which both share — and ::testTheRoleDoubleIsNotTheWholeShape() is
   * what keeps that equivalence true of what ::shapeRole() actually returns.
   */
  public function testMisspelledStubFails(): void {
    $context = $this->createStub(ComponentShapeContextInterface::class);

    $this->expectException(MethodCannotBeConfiguredException::class);
    $context->method('getScoep');
  }

  /**
   * A real method belonging to another role cannot be stubbed either.
   *
   * The failure a misspelling test cannot show. getValue() exists — on the
   * union, and on the value role — so stubbing it on a union double is
   * accepted and does nothing when the code under test asks the context role
   * instead. Narrowing is what turns that into an error.
   */
  public function testMethodOffTheRoleCannotBeStubbed(): void {
    $context = $this->createStub(ComponentShapeContextInterface::class);

    $this->assertContains(
      'getValue',
      get_class_methods(ComponentShapePluginInterface::class),
      'Premise: the name is real, so this is a wrong-role error rather than a typo.',
    );
    $this->expectException(MethodCannotBeConfiguredException::class);
    $context->method('getValue');
  }

  /**
   * Several roles can answer for one double.
   */
  public function testSeveralRolesAnswerForOneDouble(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getScope')->willReturn('entity');
    $value = $this->shapeRole(ComponentShapeValueInterface::class);
    $value->method('getOverrideValue')->willReturn('OVERRIDDEN');

    $shape = $this->shapeDouble([$context, $value]);

    $this->assertSame('entity', $shape->getScope());
    $this->assertSame('OVERRIDDEN', $shape->getOverrideValue());
  }

  /**
   * Naming a shape is a role of its own, because every other role extends it.
   *
   * A declared interface answers for what it declares itself, never for what
   * it inherits. Identity is on all fourteen roles, so the other rule —
   * forward everything reachable — would put id() on whichever role happened
   * to be listed first, which is rarely the one the test configured.
   */
  public function testIdentityIsForwardedOnlyWhenItIsDeclared(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('id')->willReturn('IGNORED');
    $identity = $this->shapeRole(ComponentShapeIdentityInterface::class);
    $identity->method('id')->willReturn('items~image~0');

    $this->assertSame('', $this->shapeDouble([$context])->id(), 'The context role does not answer for identity.');
    $this->assertSame('items~image~0', $this->shapeDouble([$context, $identity])->id());
  }

  /**
   * A capability the union does not cover is satisfied too.
   *
   * Media and style are standalone markers rather than roles, and a provider
   * tests for them with instanceof before it does anything — so the double has
   * to carry them as well as the union.
   */
  public function testCapabilityIsCarriedAlongsideTheUnion(): void {
    $media = $this->shapeRole(ComponentShapeMediaPluginInterface::class);
    $media->method('getSupportedMediaTypes')->willReturn(['image']);

    $shape = $this->shapeDouble([$media], [ComponentShapeMediaPluginInterface::class]);

    $this->assertInstanceOf(ComponentShapePluginInterface::class, $shape);
    $this->assertInstanceOf(ComponentShapeMediaPluginInterface::class, $shape);
    $this->assertSame(['image'], $shape->getSupportedMediaTypes());
  }

  /**
   * A capability that is already a shape does not double the union in.
   *
   * ComponentShapeChildrenMatchPluginInterface extends the union, so asking
   * for both would fail with "Interfaces must not declare the same method". It
   * still answers for the six methods it declares itself, alongside a role
   * answering for the union's — which is how a children-bearing shape gets
   * doubled without one wide double stubbing everything.
   */
  public function testCapabilityThatExtendsTheUnionReplacesIt(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getScope')->willReturn('entity');
    $children = $this->shapeRole(ComponentShapeChildrenMatchPluginInterface::class);
    $children->method('isSingleProp')->willReturn(TRUE);

    $shape = $this->shapeDouble([$context, $children], [ComponentShapeChildrenMatchPluginInterface::class]);

    $this->assertInstanceOf(ComponentShapeChildrenMatchPluginInterface::class, $shape);
    $this->assertSame('entity', $shape->getScope());
    $this->assertTrue($shape->isSingleProp());
  }

  /**
   * Expectations set on the role are still verified.
   *
   * Forwarding would be worthless for the write-side assertions — MediaValue's
   * shape rewiring is asserted entirely with expects() — if the calls landed
   * somewhere PHPUnit does not check.
   */
  public function testExpectationsOnTheRoleAreVerified(): void {
    $value = $this->shapeRole(ComponentShapeValueInterface::class);
    $value->expects($this->once())->method('setOverrideValue')->with('WRITTEN');

    $this->shapeDouble([$value])->setOverrideValue('WRITTEN');
  }

  /**
   * A shape nothing asks anything of says so, and fails if asked.
   *
   * Seven of the doubles this trait replaced stubbed nothing at all: the
   * provider under test never looks at its shape. That is worth stating in the
   * test rather than leaving a hundred-method double lying there implying
   * otherwise.
   */
  public function testAnUnusedShapeFailsWhenItIsAsked(): void {
    $shape = $this->unusedShape();

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('getScope() was asked of a shape double declared unused.');
    $shape->getScope();
  }

  /**
   * Declaring a role twice is a mistake rather than a silent last-wins.
   *
   * Whichever was listed first would win, so the second one's stubs would go
   * nowhere — the same silence, one level up.
   */
  public function testTheSameRoleTwiceIsRejected(): void {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);

    $this->expectException(\LogicException::class);
    $this->shapeDouble([$context, $context]);
  }

  /**
   * A double the trait did not create cannot be declared as a role.
   *
   * The trait knows which interface to forward because it created the double.
   * A stray createMock() would forward nothing, silently.
   */
  public function testForeignDoubleIsRejected(): void {
    $this->expectException(\LogicException::class);
    $this->shapeDouble([$this->createMock(ComponentShapeContextInterface::class)]);
  }

  /**
   * A role stubbed and then left undeclared fails the test.
   *
   * The last form of the mistake the trait exists to prevent: everything looks
   * configured, and nothing the role was told ever reaches the code under
   * test. One dropped element in a helper that assembles several roles does
   * it, and without this guard the test goes green — a shape mock migration
   * with this hole in it is only halfway to what the ticket asked for.
   *
   * The guard is called directly rather than left to fire at teardown, because
   * a test cannot both fail and pass. The declaration afterwards is what
   * settles it for this test's own teardown.
   */
  public function testUndeclaredRoleFails(): void {
    $stranded = $this->shapeRole(ComponentShapeValueInterface::class);
    $stranded->method('getOverrideValue')->willReturn('NEVER SEEN');

    try {
      $this->assertEveryShapeRoleWasDeclared();
      $this->fail('A role that reached no shape double was allowed through.');
    }
    catch (AssertionFailedError $error) {
      $this->assertStringContainsString('never reached a ::shapeDouble() call', $error->getMessage());
      $this->assertStringContainsString('ComponentShapeValueInterface', $error->getMessage());
    }

    $this->shapeDouble([$stranded]);
  }

}
