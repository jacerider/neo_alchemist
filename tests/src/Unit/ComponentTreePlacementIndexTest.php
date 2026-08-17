<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The placement readers answer from one index, not from a walk per call.
 *
 * This pins a complexity property rather than a result. `getComponentId()`,
 * `getComponentParentUuid()` and `getComponentSlot()` each used to guard with a
 * full scan for the UUID and then walk the tree again to answer, so
 * instantiating a single component instance walked the whole tree six times —
 * `ComponentTreeItem::getComponent()` calls all three per instance, and the
 * tree validator calls `getComponentId()` once per UUID on top of a scan that
 * already produced them. 5b1461a replaced that with one memoised pass.
 *
 * That commit argued its case from the shape of the code and changed no
 * observable behaviour, so every existing test passes just as well against the
 * six-walk version. Nothing would have failed if it were reverted, and nothing
 * measured it: the module carries no benchmark, and a wall-clock assertion
 * would be the wrong instrument anyway — it measures the CI runner as much as
 * the code.
 *
 * So assert the property directly. Build the index, replace it with records
 * that disagree with the tree, and require the readers to keep answering from
 * it. A reader that walks the tree returns the real placement and fails here.
 * That is exactly the regression, and it is detectable without timing
 * anything.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure
 */
#[Group('neo_alchemist')]
class ComponentTreePlacementIndexTest extends UnitTestCase {

  /**
   * The number of instances placed in the fixture tree.
   */
  private const PLACEMENTS = 12;

  /**
   * Builds a tree of PLACEMENTS instances, half at root and half in slots.
   */
  private function tree(): ComponentTreeStructure {
    $structure = new ComponentTreeStructure(DataDefinition::create('string'));
    $structure->applyDefaultValue(FALSE);
    $half = intdiv(self::PLACEMENTS, 2);
    for ($i = 0; $i < $half; $i++) {
      $structure->addComponent("root-$i", "card-$i");
    }
    for ($i = 0; $i < $half; $i++) {
      // Parented to a root instance so the records carry a parent and a slot.
      $structure->addComponent("child-$i", "banner-$i", "root-$i", 'content');
    }
    return $structure;
  }

  /**
   * Overwrites the built index with records that disagree with the tree.
   *
   * @param \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure $structure
   *   The structure whose index should be doctored.
   *
   * @return array
   *   The doctored records, keyed by UUID.
   */
  private function doctorIndex(ComponentTreeStructure $structure): array {
    $doctored = [];
    foreach ($structure->getComponentInstanceUuids() as $uuid) {
      $doctored[$uuid] = [
        'component' => "sentinel-component-$uuid",
        'parent' => "sentinel-parent-$uuid",
        'slot' => "sentinel-slot-$uuid",
      ];
    }
    (new \ReflectionProperty(ComponentTreeStructure::class, 'index'))
      ->setValue($structure, $doctored);
    return $doctored;
  }

  /**
   * Every placement reader consults the index instead of walking the tree.
   */
  public function testReadersAnswerFromTheIndex(): void {
    $structure = $this->tree();
    // The first read builds the index from the real tree.
    $this->assertSame('card-0', $structure->getComponentId('root-0'));

    $doctored = $this->doctorIndex($structure);

    foreach ($doctored as $uuid => $record) {
      $this->assertSame($record['component'], $structure->getComponentId($uuid));
      $this->assertSame($record['parent'], $structure->getComponentParentUuid($uuid));
      $this->assertSame($record['slot'], $structure->getComponentSlot($uuid));
    }
  }

  /**
   * Reading every placement of every instance rebuilds the index zero times.
   *
   * Three readers across PLACEMENTS instances is the call pattern
   * ComponentTreeItem::getComponent() produces for a full tree. Under the
   * per-call scans that was two walks per call; the doctored records survive
   * all of them only if the answer comes from the one pass.
   */
  public function testRepeatedReadsDoNotRebuildTheIndex(): void {
    $structure = $this->tree();
    $structure->getComponentId('root-0');
    $doctored = $this->doctorIndex($structure);

    $reads = 0;
    foreach (array_keys($doctored) as $uuid) {
      $structure->getComponentId($uuid);
      $structure->getComponentParentUuid($uuid);
      $structure->getComponentSlot($uuid);
      $reads += 3;
    }

    $this->assertSame(self::PLACEMENTS * 3, $reads);
    $index = (new \ReflectionProperty(ComponentTreeStructure::class, 'index'))
      ->getValue($structure);
    $this->assertSame($doctored, $index, 'A rebuild would have replaced the doctored records with the real tree.');
  }

  /**
   * Parent and slot come from one record, so they cannot disagree.
   */
  public function testParentAndSlotComeFromOneRecord(): void {
    $structure = $this->tree();

    $this->assertSame('root-0', $structure->getComponentParentUuid('child-0'));
    $this->assertSame('content', $structure->getComponentSlot('child-0'));

    // Drop the slot from the shared record. Both readers move together because
    // there is only one record; two walks could return the old pair.
    $index = new \ReflectionProperty(ComponentTreeStructure::class, 'index');
    $records = $index->getValue($structure);
    $records['child-0'] = ['component' => 'banner-0', 'parent' => NULL, 'slot' => NULL];
    $index->setValue($structure, $records);

    $this->assertNull($structure->getComponentParentUuid('child-0'));
    $this->assertNull($structure->getComponentSlot('child-0'));
  }

  /**
   * Changing the value drops the index rather than serving a stale placement.
   */
  public function testIndexIsDroppedWhenTheValueChanges(): void {
    $structure = $this->tree();
    $this->doctorIndex($structure);

    $structure->setValue((string) $structure, FALSE);

    $this->assertSame('card-0', $structure->getComponentId('root-0'));
    $this->assertSame('root-0', $structure->getComponentParentUuid('child-0'));
    $this->assertSame('content', $structure->getComponentSlot('child-0'));
  }

}
