<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentUsage;
use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Removing a component from a tree must leave a structurally valid tree.
 *
 * This is what keeps a component deletion from destroying its hosts: the
 * config dependency system deletes any dependent it cannot fix, so a field
 * config or Alchemist block only survives if this returns a tree that still
 * passes ComponentTreeStructureConstraintValidator. That validator is
 * unforgiving in three specific ways, and each is a case below:
 * - the root uuid key is required even when it holds nothing;
 * - a slot left with no instances must be omitted, not left empty;
 * - a subtree may not be keyed by an instance that is no longer in the tree,
 *   so removing a parent has to take its whole descendant chain with it.
 *
 * Those three describe **config** scope, and detachment reaches entity rows
 * too. A hybrid entity's row is a storage subset in which an empty flagged
 * slot is the authoritative marker for "this region was deliberately emptied",
 * so collapsing it there is a data-loss path rather than a validity
 * requirement. Which reading applies is the empty-section policy, and it is an
 * argument rather than an assumption — see EmptySectionPolicyTest for the
 * regression that motivated it.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::detachComponents()
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 */
#[Group('neo_alchemist')]
final class DetachComponentsTest extends UnitTestCase {

  /**
   * The reserved root uuid.
   */
  private const ROOT = ComponentTreeStructure::ROOT_UUID;

  /**
   * A nested fixture: root → A, B; A.region → C, D; C.slot → E; B.slot → F.
   *
   * @return array
   *   The component values.
   */
  private function fixture(): array {
    return [
      'tree' => [
        self::ROOT => [
          ['uuid' => 'A', 'component' => 'compA'],
          ['uuid' => 'B', 'component' => 'compB'],
        ],
        'A' => [
          'region' => [
            ['uuid' => 'C', 'component' => 'compC'],
            ['uuid' => 'D', 'component' => 'compD'],
          ],
        ],
        'C' => ['slot' => [['uuid' => 'E', 'component' => 'compE']]],
        'B' => ['slot' => [['uuid' => 'F', 'component' => 'compF']]],
      ],
      'props' => array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F'], ['status' => TRUE]),
    ];
  }

  /**
   * Detaches from the fixture with the config-scope policy.
   */
  private function detach(array $componentIds, ?array $values = NULL): array {
    return ComponentTreeStructure::detachComponents(
      $values ?? $this->fixture(),
      $componentIds,
      EmptySectionPolicy::Collapse,
    );
  }

  /**
   * Removing a parent takes its children and grandchildren with it.
   */
  public function testRemovingParentRemovesDescendants(): void {
    $result = $this->detach(['compA']);

    $this->assertSame([['uuid' => 'B', 'component' => 'compB']], $result['tree'][self::ROOT]);
    // A's subtree would be dangling, and C's with it.
    $this->assertArrayNotHasKey('A', $result['tree']);
    $this->assertArrayNotHasKey('C', $result['tree']);
    $this->assertArrayHasKey('B', $result['tree']);
    $this->assertSame(['B', 'F'], array_keys($result['props']));
  }

  /**
   * Removing one instance leaves its siblings in place.
   */
  public function testRemovingMidLevelInstanceKeepsSiblings(): void {
    $result = $this->detach(['compC']);

    $this->assertSame(
      [['uuid' => 'D', 'component' => 'compD']],
      $result['tree']['A']['region'],
      'The sibling stays and the tuple list is re-indexed.'
    );
    // E only existed inside C.
    $this->assertArrayNotHasKey('C', $result['tree']);
    $this->assertSame(['A', 'B', 'D', 'F'], array_keys($result['props']));
  }

  /**
   * A slot emptied by the removal is omitted, along with its subtree.
   */
  public function testEmptiedSlotIsOmitted(): void {
    $result = $this->detach(['compC', 'compD']);

    // "region" was A's only populated slot, so A's subtree goes entirely —
    // an empty subtree or empty slot is a validation error.
    $this->assertArrayNotHasKey('A', $result['tree']);
    $this->assertCount(2, $result['tree'][self::ROOT], 'A itself is still placed.');
    $this->assertSame(['A', 'B', 'F'], array_keys($result['props']));
  }

  /**
   * An emptied root keeps its key, because the validator requires it.
   */
  public function testEmptiedRootKeepsItsKey(): void {
    $result = $this->detach(['compA', 'compB']);

    $this->assertSame([self::ROOT => []], $result['tree']);
    $this->assertSame([], $result['props']);
  }

  /**
   * Removing a component that is not placed changes nothing.
   */
  public function testAbsentComponentChangesNothing(): void {
    $fixture = $this->fixture();
    $this->assertSame($fixture, $this->detach(['not_placed'], $fixture));
    $this->assertSame($fixture, $this->detach([], $fixture));
  }

  /**
   * An empty tree survives without error.
   */
  public function testEmptyTreeIsHandled(): void {
    $this->assertSame([], $this->detach(['compA'], []));
    $empty = ['tree' => [], 'props' => []];
    $this->assertSame($empty, $this->detach(['compA'], $empty));
  }

  /**
   * The same component placed more than once loses every instance.
   */
  public function testEveryInstanceOfTheComponentIsRemoved(): void {
    $values = [
      'tree' => [
        self::ROOT => [
          ['uuid' => 'A', 'component' => 'dupe'],
          ['uuid' => 'B', 'component' => 'keep'],
          ['uuid' => 'C', 'component' => 'dupe'],
        ],
      ],
      'props' => array_fill_keys(['A', 'B', 'C'], ['status' => TRUE]),
    ];
    $result = $this->detach(['dupe'], $values);

    $this->assertSame([['uuid' => 'B', 'component' => 'keep']], $result['tree'][self::ROOT]);
    $this->assertSame(['B'], array_keys($result['props']));
  }

  /**
   * Every surviving instance still has a props entry afterwards.
   *
   * The parity postcondition, on a pair that arrives already broken: a tree
   * whose props are missing an entry must not come out of detachment still
   * missing it, or the next save throws instead of the write that caused it.
   */
  public function testSurvivingInstancesGetTheirPropsBackfilled(): void {
    $values = $this->fixture();
    unset($values['props']['B']);

    $result = $this->detach(['compA'], $values);

    $this->assertSame([], $result['props']['B'] ?? NULL, 'The props-less survivor was backfilled.');
    $placed = ComponentTreeStructure::collectInstanceUuids($result['tree']);
    sort($placed);
    $stored = array_keys($result['props']);
    sort($stored);
    $this->assertSame($placed, $stored);
  }

  /**
   * Dependency names are mapped back to component ids.
   */
  public function testComponentIdsFromDependencies(): void {
    $dependencies = [
      'config' => [
        'neo_alchemist.neo_component.hero_s2' => 'entity',
        'field.storage.node.field_full' => 'entity',
        'neo_alchemist.neo_component.list_s4_2' => 'entity',
      ],
      'module' => ['node'],
    ];
    $this->assertSame(
      ['hero_s2', 'list_s4_2'],
      ComponentUsage::componentIdsFromDependencies($dependencies)
    );
    $this->assertSame([], ComponentUsage::componentIdsFromDependencies([]));
  }

}
