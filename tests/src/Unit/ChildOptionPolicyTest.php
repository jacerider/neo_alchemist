<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ChildOptionPolicy;
use Drupal\neo_alchemist\ChildShapeState;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeExpandedPluginInterface;
use Drupal\neo_alchemist\ComponentShapeOption;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parent-constrains-child rules in isolation.
 *
 * Before the policy existed these branches lived inside one shape base's
 * child-construction method, so the only way to assert them was a kernel
 * round-trip through a component, a producer and a rendered value. That is why
 * the rules were never pinned, and why one of the two bases could go on
 * ignoring them without a test noticing.
 *
 * The policy takes a parent, a child, a delta and a count and returns a
 * decision — nothing else — so every rule is assertable here.
 *
 * @see \Drupal\neo_alchemist\ChildOptionPolicy
 */
#[Group('neo_alchemist')]
class ChildOptionPolicyTest extends UnitTestCase {

  /**
   * The policy under test.
   *
   * @var \Drupal\neo_alchemist\ChildOptionPolicy
   */
  private ChildOptionPolicy $policy;

  /**
   * The child's option objects, keyed as the shape keys them.
   *
   * @var \Drupal\neo_alchemist\ComponentShapeOption[]
   */
  private array $childOptions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->policy = new ChildOptionPolicy();
    $this->childOptions = [
      'empty' => new ComponentShapeOption(FALSE, TRUE),
      'default' => new ComponentShapeOption(FALSE, TRUE),
      'access' => new ComponentShapeOption(TRUE, FALSE),
    ];
  }

  /**
   * A child shape whose options are the ones this test inspects.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape double.
   */
  private function child(): ComponentShapePluginInterface {
    $child = $this->createMock(ComponentShapePluginInterface::class);
    $child->method('id')->willReturn('root~child');
    $child->method('getOptionEmpty')->willReturn($this->childOptions['empty']);
    $child->method('getOptionDefault')->willReturn($this->childOptions['default']);
    $child->method('getOptionAccess')->willReturn($this->childOptions['access']);
    return $child;
  }

  /**
   * A parent shape with the given producer flags and own option state.
   *
   * @param array $flags
   *   Producer flags keyed `hidden`, `default` and `locked`. A key left out is
   *   never recorded at all, which is the "no producer said anything" case and
   *   is deliberately not the same as recording FALSE.
   * @param array $own
   *   The parent's own options, keyed `empty`, `default` and `access`. Each
   *   value is a ComponentShapeOption.
   * @param string $scope
   *   The parent's scope.
   * @param bool $singleProp
   *   Whether the parent reports a single property.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface
   *   The parent shape double.
   */
  private function parentShape(array $flags = [], array $own = [], string $scope = 'entity', bool $singleProp = FALSE): ComponentShapeChildrenMatchPluginInterface {
    $own += [
      'empty' => new ComponentShapeOption(FALSE, TRUE),
      'default' => new ComponentShapeOption(FALSE, TRUE),
      'access' => new ComponentShapeOption(TRUE, FALSE),
    ];
    $state = new ChildShapeState();
    foreach ([
      'hidden' => ChildShapeState::HIDDEN,
      'default' => ChildShapeState::USE_DEFAULT,
      'locked' => ChildShapeState::LOCKED,
    ] as $key => $flag) {
      if (isset($flags[$key])) {
        $state->setFlag('root~child', $flag, $flags[$key]);
      }
    }
    $parent = $this->createMock(ComponentShapeChildrenMatchPluginInterface::class);
    $parent->method('getChildShapeState')->willReturn($state);
    $parent->method('isSingleProp')->willReturn($singleProp);
    $parent->method('getScope')->willReturn($scope);
    $parent->method('getOptionEmpty')->willReturn($own['empty']);
    $parent->method('getOptionDefault')->willReturn($own['default']);
    $parent->method('getOptionAccess')->willReturn($own['access']);
    return $parent;
  }

  /**
   * A producer that flagged a child hidden locks the child's empty option.
   *
   * The rule the whole ticket turns on: a site builder leaving a child unmapped
   * is a decision, and a shape base that does not read it discards it.
   */
  public function testHiddenFlagLocksTheChildEmpty(): void {
    $this->policy->apply($this->parentShape(['hidden' => TRUE]), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['empty']->isEnabled());
  }

  /**
   * A producer that flagged a child defaulted locks the child's default.
   */
  public function testDefaultFlagLocksTheChildDefault(): void {
    $this->policy->apply($this->parentShape(['default' => TRUE]), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['default']->isEnabled());
  }

  /**
   * A flag of FALSE locks the child open, and is not the same as no flag.
   *
   * The flag is three-valued. Collapsing FALSE into "absent" would let a
   * producer's explicit "show this one" be overridden by an inherited rule.
   */
  public function testExplicitFalseFlagLocksRatherThanDefers(): void {
    $parentDefaultOn = ['default' => (new ComponentShapeOption(FALSE, TRUE))->setValue(TRUE)];

    $this->policy->apply($this->parentShape(['default' => FALSE], $parentDefaultOn), $this->child(), NULL, 2);

    $this->assertFalse(
      $this->childOptions['default']->isEnabled(),
      'A producer saying "not default" outranks the parent\'s own default state, which is applied afterwards.',
    );
  }

  /**
   * No flag leaves the child's own configuration standing.
   */
  public function testAbsentFlagsLeaveTheChildAlone(): void {
    $this->policy->apply($this->parentShape(), $this->child(), NULL, 2);

    $this->assertFalse($this->childOptions['empty']->isEnabled());
    $this->assertFalse($this->childOptions['default']->isEnabled());
    $this->assertNull($this->childOptions['empty']->isLocked());
    $this->assertNull($this->childOptions['default']->isLocked());
  }

  /**
   * A defaulted parent pushes default down onto its children.
   */
  public function testDefaultedParentDefaultsItsChildren(): void {
    $own = ['default' => (new ComponentShapeOption(FALSE, TRUE))->setValue(TRUE)];

    $this->policy->apply($this->parentShape([], $own), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['default']->isEnabled());
  }

  /**
   * An emptied parent pushes empty down onto its children.
   */
  public function testEmptiedParentEmptiesItsChildren(): void {
    $own = ['empty' => (new ComponentShapeOption(FALSE, TRUE))->setValue(TRUE)];

    $this->policy->apply($this->parentShape([], $own), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['empty']->isEnabled());
  }

  /**
   * An inaccessible parent locks its children inaccessible.
   */
  public function testInaccessibleParentLocksChildrenClosed(): void {
    $own = ['access' => (new ComponentShapeOption(TRUE, FALSE))->setValue(FALSE)];

    $this->policy->apply($this->parentShape([], $own), $this->child(), NULL, 2);

    $this->assertFalse($this->childOptions['access']->isEnabled());
  }

  /**
   * In config scope the parent's own state is not pushed down.
   *
   * A site builder authoring the component is looking at one possible
   * rendering, not deciding what the children may ever be — so the children
   * stay configurable and merely gain access.
   */
  public function testConfigScopeGrantsAccessAndInheritsNothing(): void {
    $own = [
      'default' => (new ComponentShapeOption(FALSE, TRUE))->setValue(TRUE),
      'empty' => (new ComponentShapeOption(FALSE, TRUE))->setValue(TRUE),
    ];

    $this->policy->apply($this->parentShape([], $own, 'config'), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['access']->isAllowed());
    $this->assertFalse(
      $this->childOptions['default']->isEnabled(),
      'The parent\'s current default state is a preview, not a constraint, while the component is being authored.',
    );
    $this->assertFalse($this->childOptions['empty']->isEnabled());
  }

  /**
   * A lone child of an iterating parent is offered neither toggle.
   */
  public function testLoneChildOfDeltaLosesBothToggles(): void {
    $this->policy->apply($this->parentShape(), $this->child(), 0, 1);

    $this->assertFalse($this->childOptions['default']->isAllowed());
    $this->assertFalse($this->childOptions['empty']->isAllowed());
  }

  /**
   * A child of a single-property parent is not offered the empty toggle.
   *
   * Emptying the only child empties the parent, which the parent's own toggle
   * already does.
   */
  public function testChildOfSinglePropParentLosesTheEmptyToggle(): void {
    $this->policy->apply($this->parentShape([], [], 'entity', TRUE), $this->child(), NULL, 1);

    $this->assertFalse($this->childOptions['empty']->isAllowed());
    $this->assertTrue(
      $this->childOptions['default']->isAllowed(),
      'Only the empty toggle is withdrawn here; defaulting a lone child is still meaningful.',
    );
  }

  /**
   * A parent that forces its own toggle forces its children's too.
   */
  public function testFormForcingIsInherited(): void {
    $own = [
      'default' => (new ComponentShapeOption(FALSE, TRUE))->alwaysShowForm(TRUE),
      'empty' => (new ComponentShapeOption(FALSE, TRUE))->alwaysShowForm(TRUE),
    ];

    $this->policy->apply($this->parentShape([], $own), $this->child(), NULL, 2);

    $this->assertTrue($this->childOptions['default']->isFormForced());
    $this->assertTrue($this->childOptions['empty']->isFormForced());
  }

  /**
   * An unexpandable parent withdraws every toggle from its children.
   *
   * There is no per-child UI to show them in.
   */
  public function testUnexpandableParentWithdrawsEveryToggle(): void {
    $this->policy->apply($this->unexpandableParent(), $this->child(), NULL, 2);

    $this->assertFalse($this->childOptions['default']->isAllowed());
    $this->assertFalse($this->childOptions['empty']->isAllowed());
    $this->assertFalse($this->childOptions['access']->isAllowed());
  }

  /**
   * An unexpandable parent wins over the access config scope grants.
   *
   * The rule the two interact through is that access is last-write-wins while a
   * locked value is first-write-wins, so the order these are applied in decides
   * the answer and is not free to change. A media prop is exactly this pair —
   * an object shape that refuses expansion — so getting it backwards opens
   * per-child access on every media prop in a component's configuration form.
   *
   * Extracting these branches out of the shape base reordered them and did
   * exactly that, and nothing in the suite noticed.
   */
  public function testUnexpandableParentOutranksTheConfigScopeGrant(): void {
    $this->policy->apply($this->unexpandableParent('config'), $this->child(), NULL, 2);

    $this->assertFalse(
      $this->childOptions['access']->isAllowed(),
      'Config scope grants child access, but a parent that cannot expand has nowhere to show it — the withdrawal has to be applied last.',
    );
  }

  /**
   * A parent that refuses expansion.
   *
   * @param string $scope
   *   The parent's scope.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface
   *   The parent shape double.
   */
  private function unexpandableParent(string $scope = 'entity'): ComponentShapeChildrenMatchPluginInterface {
    $parent = $this->createMockForIntersectionOfInterfaces([
      ComponentShapeChildrenMatchPluginInterface::class,
      ComponentShapeExpandedPluginInterface::class,
    ]);
    $parent->method('getChildShapeState')->willReturn(new ChildShapeState());
    $parent->method('isSingleProp')->willReturn(FALSE);
    $parent->method('getScope')->willReturn($scope);
    $parent->method('getOptionEmpty')->willReturn(new ComponentShapeOption(FALSE, TRUE));
    $parent->method('getOptionDefault')->willReturn(new ComponentShapeOption(FALSE, TRUE));
    $parent->method('getOptionAccess')->willReturn(new ComponentShapeOption(TRUE, FALSE));
    $parent->method('allowExpanded')->willReturn(FALSE);
    return $parent;
  }

}
