<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentUsage;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Removing a component from a tree must leave a structurally valid tree.
 *
 * This is what keeps a component deletion from destroying its hosts: the
 * config dependency system deletes any dependent it cannot fix, so
 * a field config or Alchemist block only survives if this returns a tree that
 * still passes ComponentTreeStructureConstraintValidator. That validator is
 * unforgiving in three specific ways, and each is a case below:
 * - the root uuid key is required even when it holds nothing;
 * - a slot left with no instances must be omitted, not left empty;
 * - a subtree may not be keyed by an instance that is no longer in the tree,
 *   so removing a parent has to take its whole descendant chain with it.
 *
 * @see \Drupal\neo_alchemist\ComponentUsage::detachComponents()
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
   * Removing a parent takes its children and grandchildren with it.
   */
  public function testRemovingParentRemovesDescendants(): void {
    $result = ComponentUsage::detachComponents($this->fixture(), ['compA']);

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
    $result = ComponentUsage::detachComponents($this->fixture(), ['compC']);

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
    $result = ComponentUsage::detachComponents($this->fixture(), ['compC', 'compD']);

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
    $result = ComponentUsage::detachComponents($this->fixture(), ['compA', 'compB']);

    $this->assertSame([self::ROOT => []], $result['tree']);
    $this->assertSame([], $result['props']);
  }

  /**
   * Removing a component that is not placed changes nothing.
   */
  public function testAbsentComponentChangesNothing(): void {
    $fixture = $this->fixture();
    $this->assertSame($fixture, ComponentUsage::detachComponents($fixture, ['not_placed']));
    $this->assertSame($fixture, ComponentUsage::detachComponents($fixture, []));
  }

  /**
   * An empty tree survives without error.
   */
  public function testEmptyTreeIsHandled(): void {
    $this->assertSame([], ComponentUsage::detachComponents([], ['compA']));
    $empty = ['tree' => [], 'props' => []];
    $this->assertSame($empty, ComponentUsage::detachComponents($empty, ['compA']));
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
    $result = ComponentUsage::detachComponents($values, ['dupe']);

    $this->assertSame([['uuid' => 'B', 'component' => 'keep']], $result['tree'][self::ROOT]);
    $this->assertSame(['B'], array_keys($result['props']));
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
