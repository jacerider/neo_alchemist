<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A relocate moves one tuple, within a section or across sections.
 *
 * Layers drag-and-drop lands a component anywhere the tree allows: before or
 * after a sibling, at the end of a region, out of a region onto the page. Only
 * the tuple that places the instance moves; its own sections (keyed by its
 * uuid) go with it untouched. A target inside the instance's own subtree, or
 * owned by nothing in the tree, is refused and nothing changes.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::relocateComponent()
 */
#[Group('neo_alchemist')]
final class ComponentTreeRelocateTest extends UnitTestCase {

  /**
   * Root [a, host, c]; host's `body` region holds [x, y]; x has a region.
   */
  private function structure(): ComponentTreeStructure {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->setValue(json_encode([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'a', 'component' => 'card'],
        ['uuid' => 'host', 'component' => 'section'],
        ['uuid' => 'c', 'component' => 'cta'],
      ],
      'host' => [
        'body' => [
          ['uuid' => 'x', 'component' => 'tabs'],
          ['uuid' => 'y', 'component' => 'card'],
        ],
      ],
      'x' => [
        'panel' => [['uuid' => 'deep', 'component' => 'card']],
      ],
    ]), FALSE);
    return $structure;
  }

  /**
   * Within the page: before a sibling, after a sibling, and to the end.
   */
  public function testWithinOneSection(): void {
    $structure = $this->structure();
    $this->assertTrue($structure->relocateComponent('c', ComponentTreeStructure::ROOT_UUID, NULL, 'a', 'before'));
    $this->assertSame(['c', 'a', 'host'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));

    $this->assertTrue($structure->relocateComponent('c', ComponentTreeStructure::ROOT_UUID, NULL, 'host', 'after'));
    $this->assertSame(['a', 'host', 'c'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));

    $this->assertTrue($structure->relocateComponent('a'));
    $this->assertSame(['host', 'c', 'a'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID), 'No sibling: the end.');
  }

  /**
   * Into a region and back out, carrying its subtree both ways.
   */
  public function testAcrossSectionsTheSubtreeTravels(): void {
    $structure = $this->structure();

    $this->assertTrue($structure->relocateComponent('x', ComponentTreeStructure::ROOT_UUID, NULL, 'a', 'after'));
    $this->assertSame(['a', 'x', 'host', 'c'], $structure->getComponentInstanceUuids(ComponentTreeStructure::ROOT_UUID));
    $this->assertSame(['y'], $structure->getComponentInstanceUuids('host', 'body'));
    $this->assertNull($structure->getComponentParentUuid('x'), 'Now on the page.');
    $this->assertSame(['deep'], $structure->getComponentInstanceUuids('x', 'panel'), 'Its own region came with it.');

    $this->assertTrue($structure->relocateComponent('c', 'host', 'body', 'y', 'before'));
    $this->assertSame(['c', 'y'], $structure->getComponentInstanceUuids('host', 'body'));
    $this->assertSame('host', $structure->getComponentParentUuid('c'));
    $this->assertSame('body', $structure->getComponentSlot('c'));
  }

  /**
   * Emptying a region leaves it an empty list.
   */
  public function testAnEmptiedSectionStays(): void {
    $structure = $this->structure();
    $structure->relocateComponent('deep');
    $this->assertSame([], $structure->getTree()['x']['panel']);
  }

  /**
   * Into itself, under itself, or under nothing: refused, nothing changes.
   */
  public function testImpossibleTargetsAreRefused(): void {
    $structure = $this->structure();
    $before = $structure->getTree();

    $this->assertFalse($structure->relocateComponent('host', 'host', 'body'), 'Into its own region.');
    $this->assertFalse($structure->relocateComponent('host', 'x', 'panel'), 'Into a region under it.');
    $this->assertFalse($structure->relocateComponent('a', 'nobody', 'body'), 'Into a section nothing in the tree owns.');
    $this->assertFalse($structure->relocateComponent('missing'), 'Not placed.');
    $this->assertFalse($structure->relocateComponent('a', 'host'), 'A non-root target needs a slot.');

    $this->assertSame($before, $structure->getTree());
  }

}
