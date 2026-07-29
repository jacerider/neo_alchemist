<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * Un-flagging a region must not destroy the entity's authored content.
 *
 * A site builder un-ticking "Entity Customizable" on one region (the
 * region_custom plugin) is the most obviously revertible config change in
 * hybrid mode — and before the fix it was the one that silently and
 * permanently destroyed every entity's authored content for that region on
 * the next save: the stored section was neither merged (no longer an
 * anchor) nor stashed as an orphan (its owner was still in the default
 * layout, which the old whole-section skip treated as "not ours").
 *
 * The layout: one na_two_region host with BOTH regions flagged; tests
 * un-flag `bottom` while `top` keeps the field hybrid.
 *
 * Scope note: un-flagging the LAST flagged region flips the whole field to
 * locked mode, where a different (known, characterized) behavior applies —
 * see LockedAndCustomModeTest and TESTING.md's residual list.
 *
 * Red/green proof: the compose-level red is carried by
 * HybridStorageExtractionTest (Unit); re-proven here by restoring the
 * pre-fix whole-section skip, which turns
 * testUnflagPreservesAuthoredContentInStorage red.
 */
#[Group('neo_alchemist')]
class HybridUnflaggedRegionTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->flagRegion('na_two_region', 'top');
    $this->flagRegion('na_two_region', 'bottom');
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultLayout(): array {
    return [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => static::HOST_UUID, 'component' => 'na_two_region'],
        ],
        static::HOST_UUID => [
          'top' => [['uuid' => 'seed-top', 'component' => 'na_leaf']],
          'bottom' => [['uuid' => 'seed-bottom', 'component' => 'na_leaf']],
        ],
      ],
      'props' => [
        static::HOST_UUID => ['status' => TRUE, 'props' => []],
        'seed-top' => $this->leafProps('SEED TOP'),
        'seed-bottom' => $this->leafProps('SEED BOTTOM'),
      ],
    ];
  }

  /**
   * Authors content into both regions and returns the saved entity.
   */
  private function entityWithBothRegionsAuthored(): EntityTest {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['top'] = [['uuid' => 'top-leaf', 'component' => 'na_leaf']];
    $tree[static::HOST_UUID]['bottom'] = [['uuid' => 'bottom-leaf', 'component' => 'na_leaf']];
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => $tree,
        'props' => $defaults['props'] + [
          'top-leaf' => $this->leafProps('AUTHORED TOP'),
          'bottom-leaf' => $this->leafProps('AUTHORED BOTTOM'),
        ],
      ],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * The authored content of an un-flagged region survives the next save.
   */
  public function testUnflagPreservesAuthoredContentInStorage(): void {
    $entity = $this->entityWithBothRegionsAuthored();

    $this->unflagRegion('na_two_region', 'bottom');

    // The field is still hybrid through `top`.
    $entity = $this->reloadEntity($entity);
    $this->assertFieldIsHybrid($entity);

    // The dangerous moment: a plain resave while bottom is un-flagged.
    $entity->save();

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame(
      [['uuid' => 'bottom-leaf', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['bottom'] ?? NULL,
      'The un-flagged region content survived the save as an orphaned slot.',
    );
    $this->assertSame($this->leafProps('AUTHORED BOTTOM'), $stored['props']['bottom-leaf'] ?? NULL, 'The orphaned tuple kept its props.');
    $this->assertSame(
      [['uuid' => 'top-leaf', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['top'] ?? NULL,
      'The still-flagged region was unaffected.',
    );
  }

  /**
   * An un-flagged region renders the default seed, not the stored content.
   */
  public function testUnflaggedContentIsRenderInert(): void {
    $entity = $this->entityWithBothRegionsAuthored();
    $this->unflagRegion('na_two_region', 'bottom');

    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);

    $this->assertSame(
      [['uuid' => 'seed-bottom', 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['bottom'] ?? NULL,
      'The un-flagged region shows the default layout again.',
    );
    $this->assertSame(
      [['uuid' => 'top-leaf', 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['top'] ?? NULL,
      'The still-flagged region keeps the authored content.',
    );
  }

  /**
   * Re-flagging the region restores the authored content.
   */
  public function testReflagRestoresAuthoredContent(): void {
    $entity = $this->entityWithBothRegionsAuthored();
    $this->unflagRegion('na_two_region', 'bottom');

    // A save cycle while un-flagged — content rides along as an orphan.
    $entity = $this->reloadEntity($entity);
    $entity->save();

    $this->flagRegion('na_two_region', 'bottom');

    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'bottom-leaf', 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['bottom'] ?? NULL,
      'Re-flagging brought the authored content back.',
    );
  }

}
