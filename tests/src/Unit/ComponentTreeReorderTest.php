<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A reorder is a permutation, never a deletion.
 *
 * The reported defect: a site builder reorders components in a layout and one
 * of them silently disappears. The old `sortComponents()` rebuilt the section
 * from the list of UUIDs it was handed and discarded everything else — and its
 * callers built that list from `ComponentTreeItem::toOptions()`, which can
 * only offer a row for an instance whose `neo_component` config still loads.
 * So a section holding [A, B, C] where A's component is missing became [C, B]
 * after a single "move down" on B: A was gone from the tree, while its subtree
 * and every descendant's props stayed in storage in exactly the dangling state
 * the structure validator rejects.
 *
 * These tests are the seam-level pins for the fix. The presentation helper is
 * belt-and-braces (`ComponentTreeItem::getPlacedUuids()` now feeds the reorder
 * callers a complete list); what closes the defect is that no list a caller
 * can pass is capable of removing anything.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::reorderComponents()
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::getPlacedUuids()
 */
#[Group('neo_alchemist')]
final class ComponentTreeReorderTest extends UnitTestCase {

  /**
   * Builds a structure holding the given decoded tree.
   */
  private function structure(array $tree): ComponentTreeStructure {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->setValue(json_encode($tree), FALSE);
    return $structure;
  }

  /**
   * Root [A, B, C] where A is unresolvable, moving B down past C.
   *
   * `broken` has a subtree and its child has props, so a deletion here is the
   * data-loss shape the defect produced, not just a missing row.
   */
  private function damagedTree(): array {
    return [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'broken', 'component' => 'deleted_component'],
        ['uuid' => 'b', 'component' => 'card'],
        ['uuid' => 'c', 'component' => 'cta'],
      ],
      'broken' => [
        'content' => [['uuid' => 'stranded', 'component' => 'card']],
      ],
    ];
  }

  /**
   * The reported defect: an unlabelable instance survives a sibling's move.
   *
   * The caller passes what the labelling helper gave it — [b, c] with the
   * broken instance missing — already swapped to [c, b].
   */
  public function testUnresolvableInstanceSurvivesReorder(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents(['c', 'b']);

    $this->assertSame(
      ['broken', 'c', 'b'],
      $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID),
      'The move happened and the unlisted instance kept its position.',
    );
    $this->assertSame('deleted_component', $structure->getComponentId('broken'));
  }

  /**
   * The unresolvable instance keeps its subtree and its descendants.
   *
   * Losing the tuple while leaving the section behind is what strands content:
   * props are keyed by instance UUID with no parent links, so once a tuple is
   * gone nothing can work out which prop values belonged underneath it.
   */
  public function testTheSurvivingInstanceKeepsItsSubtree(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents(['c', 'b']);

    $this->assertSame(['stranded'], $structure->getComponentInstanceUuids('broken', 'content'));
    $this->assertSame('broken', $structure->getComponentParentUuid('stranded'));
  }

  /**
   * A reorder is a permutation of the section: same members, new order.
   */
  public function testReorderIsPermutation(): void {
    $structure = $this->structure($this->damagedTree());
    $before = $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID);

    $structure->reorderComponents(['c', 'b']);
    $after = $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID);

    sort($before);
    sort($after);
    $this->assertSame($before, $after);
  }

  /**
   * An empty list moves nothing at all.
   */
  public function testEmptyListChangesNothing(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents([]);

    $this->assertSame(['broken', 'b', 'c'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));
  }

  /**
   * UUIDs that are not placed in the section contribute nothing.
   *
   * A stale editor tab or a hand-built request can name an instance that has
   * since moved or been deleted; it must not displace anything.
   */
  public function testUnknownUuidsAreIgnored(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents(['ghost', 'c', 'stranded', 'b']);

    $this->assertSame(['broken', 'c', 'b'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));
  }

  /**
   * A UUID listed twice cannot evict a sibling.
   *
   * The absolute claim in the user story is that a reorder is *incapable* of
   * removing an instance, so a caller that repeats a UUID — a double-submitted
   * tabledrag, a hand-built request — must not be able to write it into two
   * positions and overwrite whatever sat in the second one.
   */
  public function testDuplicateUuidsCannotEvictSiblings(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents(['c', 'c', 'b']);

    $this->assertSame(
      ['broken', 'c', 'b'],
      $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID),
      'The repeat was ignored; nothing was evicted.',
    );
  }

  /**
   * Only the listed positions are refilled; the gaps stay where they are.
   *
   * With [A, B, C, D] and a reorder of [D, B], the two positions B and D
   * occupy are refilled in the requested order and A and C never move.
   */
  public function testUnlistedMembersHoldTheirIndex(): void {
    $structure = $this->structure([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'a', 'component' => 'x'],
        ['uuid' => 'b', 'component' => 'x'],
        ['uuid' => 'c', 'component' => 'x'],
        ['uuid' => 'd', 'component' => 'x'],
      ],
    ]);

    $structure->reorderComponents(['d', 'b']);

    $this->assertSame(['a', 'd', 'c', 'b'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));
  }

  /**
   * Reordering inside a slot behaves identically.
   */
  public function testReorderWithinSlot(): void {
    $structure = $this->structure([
      ComponentTreeStructure::ROOT_UUID => [['uuid' => 'parent', 'component' => 'container']],
      'parent' => [
        'content' => [
          ['uuid' => 'broken', 'component' => 'deleted_component'],
          ['uuid' => 'x', 'component' => 'card'],
          ['uuid' => 'y', 'component' => 'card'],
        ],
      ],
    ]);

    $structure->reorderComponents(['y', 'x'], 'parent', 'content');

    $this->assertSame(['broken', 'y', 'x'], $structure->getComponentInstanceUuids('parent', 'content'));
  }

  /**
   * The reordered section stays a JSON list, not an object.
   *
   * A section re-encoded as a JSON object fails the structure validator and
   * breaks every consumer that reads it positionally.
   */
  public function testSectionStaysJsonList(): void {
    $structure = $this->structure($this->damagedTree());

    $structure->reorderComponents(['c', 'b']);

    $decoded = json_decode((string) $structure, TRUE);
    $this->assertTrue(array_is_list($decoded[ComponentTreeStructure::ROOT_UUID]));
  }

}
