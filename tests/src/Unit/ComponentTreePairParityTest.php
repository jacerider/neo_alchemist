<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tree↔props parity is a postcondition, not a rule six callers remember.
 *
 * `props` is a flat map keyed by instance UUID with no parent links, so the
 * only moment at which the prop values belonging under a removed instance can
 * be identified is while that instance's descendant closure is still known.
 * Parity used to be maintained by hand in six places and enforced by a single
 * save-time LogicException whose message read "Put a breakpoint here and
 * figure out why" — an admission that the invariant had no owner.
 *
 * It has one now: bind the props companion and every operation that adds or
 * removes an instance keeps the pair in step. Unbound, the tree is written on
 * its own exactly as before, which is what config-scope and read-only callers
 * want.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::bindProps()
 */
#[Group('neo_alchemist')]
final class ComponentTreePairParityTest extends UnitTestCase {

  /**
   * The props companion built alongside each tree.
   */
  private ComponentPropsValues $props;

  /**
   * Builds an empty bound tree plus its props companion.
   */
  private function boundTree(): ComponentTreeStructure {
    $this->props = new ComponentPropsValues(DataDefinition::create('string'));
    $this->props->applyDefaultValue(FALSE);
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->applyDefaultValue(FALSE);
    return $structure->bindProps($this->props);
  }

  /**
   * Asserts that every placed instance has a props entry and vice versa.
   */
  private function assertParity(ComponentTreeStructure $structure): void {
    $placed = $structure->getComponentInstanceUuids();
    sort($placed);
    $stored = $this->props->getComponentInstanceUuids();
    sort($stored);
    $this->assertSame($placed, $stored, 'The tree and props hold the same instance UUIDs.');
  }

  /**
   * Adding an instance creates its props entry.
   */
  public function testAddCreatesPropsEntry(): void {
    $structure = $this->boundTree();

    $structure->addComponent('a', 'card', ComponentTreeStructure::ROOT_UUID, NULL, ['title' => 'Hi']);
    $structure->addComponent('b', 'banner');

    $this->assertParity($structure);
    $this->assertSame(['title' => 'Hi'], $this->props->getComponentPropsSources('a'));
    $this->assertSame([], $this->props->getComponentPropsSources('b'), 'An add with no values still gets an entry.');
  }

  /**
   * Re-adding an instance does not clobber the props it already had.
   *
   * `addComponent()` doubles as a move — it removes the UUID from its section
   * before appending — and a move must not blank the instance's values.
   */
  public function testReAddKeepsExistingProps(): void {
    $structure = $this->boundTree();
    $structure->addComponent('a', 'card', ComponentTreeStructure::ROOT_UUID, NULL, ['title' => 'Hi']);

    $structure->addComponent('a', 'card');

    $this->assertSame(['title' => 'Hi'], $this->props->getComponentPropsSources('a'));
  }

  /**
   * Removing an instance drops the whole subtree's props with it.
   */
  public function testRemoveDropsDescendantProps(): void {
    $structure = $this->boundTree();
    $structure->addComponent('parent', 'container', ComponentTreeStructure::ROOT_UUID, NULL, ['x' => 1]);
    $structure->addComponent('child', 'card', 'parent', 'content', ['x' => 2]);
    $structure->addComponent('grandchild', 'cta', 'child', 'content', ['x' => 3]);
    $structure->addComponent('sibling', 'card', ComponentTreeStructure::ROOT_UUID, NULL, ['x' => 4]);

    $structure->removeComponent('parent', EmptySectionPolicy::Preserve);

    $this->assertSame(['sibling'], $this->props->getComponentInstanceUuids());
    $this->assertParity($structure);
  }

  /**
   * Parity holds after a mixed sequence of operations.
   *
   * The property the save-time check exists to catch, expressed directly.
   */
  public function testParityHoldsAcrossOperationSequence(): void {
    $structure = $this->boundTree();
    $structure->addComponent('a', 'card', ComponentTreeStructure::ROOT_UUID, NULL, ['x' => 1]);
    $structure->addComponent('b', 'container', ComponentTreeStructure::ROOT_UUID, NULL, ['x' => 2]);
    $structure->addComponent('c', 'card', 'b', 'content', ['x' => 3]);
    $this->assertParity($structure);

    $structure->reorderComponents(['b', 'a']);
    $this->assertParity($structure);

    $structure->removeComponent('b', EmptySectionPolicy::Collapse);
    $this->assertParity($structure);

    $structure->addComponent('d', 'cta');
    $this->assertParity($structure);
  }

  /**
   * An unbound tree writes nothing to any props companion.
   *
   * The config-scope and read-only path: the existing sixteen unit tests build
   * a structure in one line with no props at all, and must keep working.
   */
  public function testUnboundTreeTouchesNoProps(): void {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->applyDefaultValue(FALSE);
    // No props companion at all: the operations below must still complete.
    $structure->addComponent('a', 'card');
    $structure->removeComponent('a', EmptySectionPolicy::Collapse);

    $this->assertSame([], $structure->getComponentInstanceUuids());
  }

}
