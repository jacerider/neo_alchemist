<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the component tree algebra.
 *
 * This is the data structure every placed component is addressed through, and
 * it is pure PHP — no container, no entities. The depth-first traversal in
 * particular is load-bearing: ComponentTreeHydrated::renderify() relies on
 * children being yielded before their parents so a parent's slots are already
 * populated by the time it is assembled.
 */
#[Group('neo_alchemist')]
class ComponentTreeStructureTest extends UnitTestCase {

  /**
   * Builds an empty tree containing only the root key.
   */
  private function tree(): ComponentTreeStructure {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->applyDefaultValue(FALSE);
    return $structure;
  }

  /**
   * A default tree carries the root key and nothing else.
   */
  public function testDefaultValueIsRootOnly(): void {
    $structure = $this->tree();

    $this->assertSame([], $structure->getComponents());
    $this->assertSame([], $structure->getComponentInstanceUuids());
    $this->assertJsonStringEqualsJsonString(
      '{"' . ComponentTreeStructure::ROOT_UUID . '": []}',
      (string) $structure,
    );
  }

  /**
   * Components added at the root round-trip through JSON.
   */
  public function testAddComponentAtRoot(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');
    $structure->addComponent('uuid-b', 'banner');

    $this->assertSame(['uuid-a', 'uuid-b'], $structure->getComponentInstanceUuids());
    $this->assertSame('card', $structure->getComponentId('uuid-a'));
    $this->assertSame('banner', $structure->getComponentId('uuid-b'));
  }

  /**
   * A root-placed component reports neither a parent UUID nor a slot.
   *
   * Both lookups skip the root section outright, so "no parent" is expressed
   * as NULL rather than as ROOT_UUID. Callers distinguishing root placement
   * from an unknown UUID cannot do it with these two methods alone — they
   * return NULL for both.
   */
  public function testRootComponentsHaveNoParentOrSlot(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');

    $this->assertNull($structure->getComponentParentUuid('uuid-a'));
    $this->assertNull($structure->getComponentSlot('uuid-a'));
  }

  /**
   * Re-adding an existing UUID moves it rather than duplicating it.
   */
  public function testAddComponentIsIdempotentOnUuid(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');
    $structure->addComponent('uuid-b', 'banner');
    $structure->addComponent('uuid-a', 'card');

    $this->assertSame(['uuid-b', 'uuid-a'], $structure->getComponentInstanceUuids(), 'Re-adding moves it to the end.');
  }

  /**
   * A component placed in a parent slot reports that parent and slot.
   */
  public function testAddComponentIntoSlot(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $this->assertSame(['child'], $structure->getComponentInstanceUuids('parent', 'content'));
    $this->assertSame('parent', $structure->getComponentParentUuid('child'));
    $this->assertSame('content', $structure->getComponentSlot('child'));
  }

  /**
   * Removing a component also drops the subtree it owned.
   */
  public function testRemoveComponentDropsItsSubtree(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $structure->removeComponent('parent', EmptySectionPolicy::Preserve);

    $this->assertNotContains('parent', $structure->getComponentInstanceUuids());
    $this->assertNull($structure->getComponentId('child'), 'The orphaned child is gone with its parent.');
    $this->assertStringNotContainsString('parent', (string) $structure);
  }

  /**
   * Removing a child leaves its siblings and parent intact.
   */
  public function testRemoveComponentLeavesSiblings(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child-a', 'card', 'parent', 'content');
    $structure->addComponent('child-b', 'card', 'parent', 'content');

    $structure->removeComponent('child-a', EmptySectionPolicy::Preserve);

    $this->assertSame(['child-b'], $structure->getComponentInstanceUuids('parent', 'content'));
    $this->assertSame('container', $structure->getComponentId('parent'));
  }

  /**
   * Preserve keeps a slot the removal emptied, as an explicit empty marker.
   */
  public function testRemoveComponentPreservesTheEmptiedSlot(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $structure->removeComponent('child', EmptySectionPolicy::Preserve);

    $this->assertSame([], $structure->getComponentsBySection('parent', 'content'));
    $this->assertArrayHasKey('parent', json_decode((string) $structure, TRUE));
  }

  /**
   * Collapse drops a slot the removal emptied, and its section with it.
   */
  public function testRemoveComponentCollapsesTheEmptiedSlot(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $structure->removeComponent('child', EmptySectionPolicy::Collapse);

    $decoded = json_decode((string) $structure, TRUE);
    $this->assertArrayNotHasKey('parent', $decoded, 'A subtree with no populated slot is omitted.');
    $this->assertSame('container', $structure->getComponentId('parent'), 'The instance itself is still placed.');
  }

  /**
   * Reordering permutes a section to match the given UUID order.
   */
  public function testReorderComponentsAtRoot(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');
    $structure->addComponent('uuid-b', 'banner');
    $structure->addComponent('uuid-c', 'cta');

    $structure->reorderComponents(['uuid-c', 'uuid-a', 'uuid-b']);

    $this->assertSame(['uuid-c', 'uuid-a', 'uuid-b'], $structure->getComponentInstanceUuids());
  }

  /**
   * UUIDs omitted from a reorder keep their positions.
   *
   * The inversion of a test that used to characterise the defect: the
   * destructive predecessor rebuilt the section from the supplied list, so a
   * caller passing a partial list silently deleted the rest. A reorder is a
   * permutation now, never a deletion.
   *
   * @see \Drupal\Tests\neo_alchemist\Unit\ComponentTreeReorderTest
   */
  public function testReorderComponentsKeepsOmittedUuids(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');
    $structure->addComponent('uuid-b', 'banner');

    $structure->reorderComponents(['uuid-b']);

    $this->assertSame(['uuid-a', 'uuid-b'], $structure->getComponentInstanceUuids());
  }

  /**
   * Reordering a non-root parent without naming a slot is rejected.
   */
  public function testReorderComponentsRequiresSlotForNonRoot(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $this->expectException(\InvalidArgumentException::class);
    $structure->reorderComponents(['child'], 'parent');
  }

  /**
   * Asking for a slot that does not exist is an error, not an empty list.
   */
  public function testMissingSlotThrows(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $this->expectException(\UnexpectedValueException::class);
    $structure->getComponentsBySection('parent', 'nope');
  }

  /**
   * A slot holding a non-array payload is an error.
   */
  public function testNonArraySlotPayloadThrows(): void {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->setValue(json_encode([
      ComponentTreeStructure::ROOT_UUID => [['uuid' => 'parent', 'component' => 'container']],
      'parent' => ['content' => 'not-an-array'],
    ]), FALSE);

    $this->expectException(\UnexpectedValueException::class);
    $structure->getComponentsBySection('parent', 'content');
  }

  /**
   * Unknown UUIDs resolve to NULL rather than throwing.
   */
  public function testUnknownUuidsResolveToNull(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');

    $this->assertNull($structure->getComponentId('nope'));
    $this->assertNull($structure->getComponentParentUuid('nope'));
    $this->assertNull($structure->getComponentSlot('nope'));
  }

  /**
   * Children are yielded before their parents.
   *
   * ComponentTreeHydrated::renderify() assembles each component into its
   * parent's slot as it walks this generator, so a parent appearing before its
   * own children would render half-populated slots.
   */
  public function testSlotChildrenYieldedDepthFirst(): void {
    $structure = $this->tree();
    $structure->addComponent('grandparent', 'container');
    $structure->addComponent('parent', 'container', 'grandparent', 'content');
    $structure->addComponent('child', 'card', 'parent', 'content');

    $yielded = [];
    foreach ($structure->getSlotChildrenDepthFirst() as $parentUuid => $info) {
      $yielded[] = $parentUuid . ':' . $info['slot'] . ':' . $info['uuid'];
    }

    $this->assertContains('parent:content:child', $yielded);
    $this->assertContains('grandparent:content:parent', $yielded);
    $this->assertLessThan(
      array_search('grandparent:content:parent', $yielded, TRUE),
      array_search('parent:content:child', $yielded, TRUE),
      'The deeper child is yielded before its parent.',
    );
  }

  /**
   * Root-level components never appear in the slot traversal.
   */
  public function testDepthFirstSkipsRootComponents(): void {
    $structure = $this->tree();
    $structure->addComponent('uuid-a', 'card');
    $structure->addComponent('uuid-b', 'banner');

    $this->assertSame([], iterator_to_array($structure->getSlotChildrenDepthFirst(), FALSE));
  }

  /**
   * Mutating the tree invalidates the cached traversal graph.
   */
  public function testGraphIsRecomputedAfterMutation(): void {
    $structure = $this->tree();
    $structure->addComponent('parent', 'container');
    $structure->addComponent('child-a', 'card', 'parent', 'content');

    $first = iterator_to_array($structure->getSlotChildrenDepthFirst(), FALSE);
    $this->assertCount(1, $first);

    $structure->addComponent('child-b', 'card', 'parent', 'content');
    $second = iterator_to_array($structure->getSlotChildrenDepthFirst(), FALSE);

    $this->assertCount(2, $second, 'The generator reflects the mutation.');
  }

}
