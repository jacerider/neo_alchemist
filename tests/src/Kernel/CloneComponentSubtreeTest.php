<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cloning a component clones its whole subtree, slots intact.
 *
 * The old implementation read the clone source's children using the slot
 * the source itself SITS IN ($component->getParentSlot()) as the slot to
 * look inside it — throwing UnexpectedValueException whenever the names
 * differed (and working at root only because a NULL slot falls into the
 * all-slots branch) — and never recursed, so grandchildren were silently
 * dropped from every clone.
 *
 * Red/green proof performed during development: with the old children loop
 * restored, testCloneNestedComponentWithDifferentSlotName goes red with the
 * UnexpectedValueException and testCloneCopiesGrandchildren goes red with
 * the grandchild missing.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::cloneComponent()
 */
#[Group('neo_alchemist')]
class CloneComponentSubtreeTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Clone tests want plain custom mode: arbitrary per-entity trees.
   */
  protected function setUp(): void {
    parent::setUp();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('na_region_host');
  }

  /**
   * Builds an entity holding root → P(host), P.body → D(duo), D.top → L.
   */
  private function itemWithNestedTree(): ComponentTreeItem {
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'p-host', 'component' => 'na_region_host'],
          ],
          'p-host' => [
            'body' => [['uuid' => 'd-duo', 'component' => 'na_two_region']],
          ],
          'd-duo' => [
            'top' => [['uuid' => 'l-leaf', 'component' => 'na_leaf']],
          ],
        ],
        'props' => [
          'p-host' => [
            'status' => TRUE,
            'props' => [
              'heading' => ['ref' => 'string', 'value' => ['value' => 'HOST']],
            ],
          ],
          'd-duo' => ['status' => TRUE, 'props' => []],
          'l-leaf' => $this->leafProps('LEAF'),
        ],
      ],
    ]);
    $entity->save();
    $item = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  /**
   * Decodes the item's current tree.
   */
  private function decodedTree(ComponentTreeItem $item): array {
    return Json::decode($item->get('tree')->getValue()) ?: [];
  }

  /**
   * Cloning a component whose own slots differ from its parent slot works.
   *
   * D sits in P's `body` slot while its own children live in `top` — the
   * exact geometry the old parent-slot lookup exploded on.
   */
  public function testCloneNestedComponentWithDifferentSlotName(): void {
    $item = $this->itemWithNestedTree();
    $source = $item->getComponent('d-duo');
    $this->assertNotNull($source, 'Premise: the nested component resolves.');

    $cloned = $item->cloneComponent($source);

    $tree = $this->decodedTree($item);
    $this->assertNotSame('d-duo', $cloned->uuid(), 'The clone received a fresh uuid.');
    $this->assertContains($cloned->uuid(), array_column($tree['p-host']['body'], 'uuid'), 'The clone landed in the same slot as its source.');
    $this->assertSame(
      [['uuid' => 'l-leaf', 'component' => 'na_leaf']],
      $tree['d-duo']['top'],
      'The source subtree is untouched.',
    );
  }

  /**
   * Grandchildren are cloned, recursively, into the right slots.
   */
  public function testCloneCopiesGrandchildren(): void {
    $item = $this->itemWithNestedTree();
    $source = $item->getComponent('p-host');

    $cloned = $item->cloneComponent($source);

    $tree = $this->decodedTree($item);
    $clonedChildTuples = $tree[$cloned->uuid()]['body'] ?? [];
    $this->assertCount(1, $clonedChildTuples, 'The clone carries its child.');
    $clonedChildUuid = $clonedChildTuples[0]['uuid'];
    $this->assertNotSame('d-duo', $clonedChildUuid, 'The child clone received a fresh uuid.');

    $clonedGrandchildTuples = $tree[$clonedChildUuid]['top'] ?? [];
    $this->assertCount(1, $clonedGrandchildTuples, 'The grandchild was cloned too — one level was not enough.');
    $this->assertNotSame('l-leaf', $clonedGrandchildTuples[0]['uuid'] ?? NULL);
    $this->assertSame('na_leaf', $clonedGrandchildTuples[0]['component'] ?? NULL);
  }

  /**
   * Clones carry props, get unique uuids, and sit after their source.
   */
  public function testClonePreservesSlotsPropsAndGeneratesNewUuids(): void {
    $item = $this->itemWithNestedTree();
    $source = $item->getComponent('p-host');

    $cloned = $item->cloneComponent($source);

    $tree = $this->decodedTree($item);
    $props = Json::decode($item->get('props')->getValue()) ?: [];

    // Root order: source first, clone directly after.
    $rootUuids = array_column($tree[ComponentTreeStructure::ROOT_UUID], 'uuid');
    $this->assertSame(['p-host', $cloned->uuid()], $rootUuids, 'The clone is positioned directly after its source.');

    // Every cloned uuid is fresh and unique.
    $clonedChildUuid = $tree[$cloned->uuid()]['body'][0]['uuid'];
    $clonedGrandchildUuid = $tree[$clonedChildUuid]['top'][0]['uuid'];
    $allUuids = [
      'p-host', 'd-duo', 'l-leaf',
      $cloned->uuid(), $clonedChildUuid, $clonedGrandchildUuid,
    ];
    $this->assertSame($allUuids, array_unique($allUuids), 'No uuid collisions between source and clone.');

    // Props follow each clone.
    $this->assertSame($props['p-host'], $props[$cloned->uuid()] ?? NULL, 'The clone carries its source props.');
    $this->assertSame($props['l-leaf'], $props[$clonedGrandchildUuid] ?? NULL, 'The grandchild clone carries its source props.');
  }

}
