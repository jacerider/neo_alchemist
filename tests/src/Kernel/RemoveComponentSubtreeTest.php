<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * Removing a component removes its whole subtree.
 *
 * Removal used to reach exactly one level down: the tree dropped the removed
 * component's own section and unlinked it from its parent, and the item level
 * removed props for the component plus its DIRECT children. A grandchild kept
 * both its section and its props, leaving the orphaned run of sections that
 * ComponentTreeStructureConstraintValidator reports as a dangling subtree and
 * ComponentTreeHydrated silently discards at render.
 *
 * The saveComponents() sweep cannot catch the leak either: a grandchild is
 * still a well-formed tuple inside the now-unreachable child section, so
 * getComponent() resolves it and nothing prunes it.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::removeComponent()
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::removeComponent()
 */
#[Group('neo_alchemist')]
class RemoveComponentSubtreeTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Plain custom mode: arbitrary per-entity trees.
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
    $item = $entity->get(static::FIELD_NAME)->first();
    assert($item instanceof ComponentTreeItem);
    return $item;
  }

  /**
   * Removing the parent leaves no descendant section behind.
   */
  public function testRemoveDropsDescendantSections(): void {
    $item = $this->itemWithNestedTree();

    $item->removeComponent('p-host');

    $tree = Json::decode((string) $item->get('tree')->getValue());
    $this->assertArrayNotHasKey('p-host', $tree, 'The removed component kept its own section.');
    $this->assertArrayNotHasKey('d-duo', $tree, 'The child section survived removal of its parent.');
    $this->assertArrayNotHasKey('l-leaf', $tree, 'The grandchild section survived removal of its grandparent.');
    $this->assertSame([], $tree[ComponentTreeStructure::ROOT_UUID], 'The root still lists the removed component.');
  }

  /**
   * Removing the parent removes props for the whole subtree, not one level.
   */
  public function testRemoveDropsDescendantProps(): void {
    $item = $this->itemWithNestedTree();

    $item->removeComponent('p-host');

    $props = Json::decode((string) $item->get('props')->getValue());
    $this->assertArrayNotHasKey('p-host', $props, 'Props for the removed component survived.');
    $this->assertArrayNotHasKey('d-duo', $props, 'Props for the direct child survived.');
    $this->assertArrayNotHasKey('l-leaf', $props, 'Props for the grandchild survived — the leak this guards.');
  }

  /**
   * Removing a mid-tree component leaves its ancestors untouched.
   */
  public function testRemoveOnlyTakesTheTargetSubtree(): void {
    $item = $this->itemWithNestedTree();

    $item->removeComponent('d-duo');

    $tree = Json::decode((string) $item->get('tree')->getValue());
    $props = Json::decode((string) $item->get('props')->getValue());

    $this->assertArrayHasKey('p-host', $tree, 'Removing a child took its parent with it.');
    $this->assertSame([], $tree['p-host']['body'], 'The parent still lists the removed child.');
    $this->assertArrayNotHasKey('d-duo', $tree, 'The removed component kept its section.');
    $this->assertArrayNotHasKey('l-leaf', $tree, 'The grandchild section survived.');

    $this->assertArrayHasKey('p-host', $props, 'Removing a child dropped its parent props.');
    $this->assertArrayNotHasKey('d-duo', $props);
    $this->assertArrayNotHasKey('l-leaf', $props, 'Grandchild props survived.');
  }

}
