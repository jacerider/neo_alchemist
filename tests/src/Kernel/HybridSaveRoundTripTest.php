<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * The core hybrid contract: merge → strip → merge is lossless.
 *
 * Hybrid mode is live in production (node.project via project_full,
 * taxonomy_term.market via hero_s2), so these are regression pins over
 * shipped behavior: seed copy-on-write, explicitly-empty slots, nested
 * descendants, and tree/props parity through a real entity save.
 *
 * Red/green proof performed during development: with the inverted parity
 * guard restored (`!isset($storageTree[$uuid]) &&` in
 * extractHybridStorageValue()), testNestedDescendantsRoundTrip goes red with
 * the LogicException from ComponentTreeItem::preSave().
 */
#[Group('neo_alchemist')]
class HybridSaveRoundTripTest extends HybridFieldKernelTestBase {

  /**
   * An untouched entity persists nothing.
   */
  public function testSeedCopyOnWriteStartsEmpty(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $this->assertNull($this->rawStoredValue($entity), 'An uncustomized entity stores no field row — the default stays fully authoritative.');

    // The runtime value still renders the default layout.
    $list = $entity->get(static::FIELD_NAME);
    $this->assertTrue($list->isDefault(), 'The list reports the default value.');
  }

  /**
   * First customization copies the region subset — and only it — to storage.
   */
  public function testFirstCustomizationStoresOnlyTheSubset(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-1', 'component' => 'na_leaf'],
    ], [
      'authored-1' => $this->leafProps('AUTHORED'),
    ]);

    $stored = $this->rawStoredValue($this->reloadEntity($entity));

    $this->assertNotNull($stored, 'A customized entity stores a row.');
    $this->assertSame(
      [ComponentTreeStructure::ROOT_UUID, static::HOST_UUID],
      array_keys($stored['tree']),
      'The stored tree holds only the empty root and the anchor section — never the whole layout.',
    );
    $this->assertSame(
      [['uuid' => 'authored-1', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['body'],
    );
    $this->assertSame($this->leafProps('AUTHORED'), $stored['props']['authored-1'] ?? NULL);
    $this->assertArrayNotHasKey(static::HOST_UUID, $stored['props'], 'The inherited host contributes no per-entity props.');
  }

  /**
   * Merge → strip → merge is idempotent across save cycles.
   */
  public function testMergeStripMergeIsIdempotent(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-1', 'component' => 'na_leaf'],
    ], [
      'authored-1' => $this->leafProps('AUTHORED'),
    ]);

    $entity = $this->reloadEntity($entity);
    $storedAfterFirst = $this->rawStoredValue($entity);
    // A plain resave of the reloaded (re-merged) entity.
    $entity->save();
    $storedAfterSecond = $this->rawStoredValue($this->reloadEntity($entity));

    $this->assertSame($storedAfterFirst, $storedAfterSecond, 'A load/save cycle changes nothing in storage.');

    // And the merged runtime value is stable too.
    $merged = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue();
    $tree = json_decode($merged['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'authored-1', 'component' => 'na_leaf']],
      $tree[static::HOST_UUID]['body'],
      'The merged value carries the authored region content.',
    );
    $this->assertSame(
      [['uuid' => static::HOST_UUID, 'component' => 'na_region_host']],
      $tree[ComponentTreeStructure::ROOT_UUID],
      'The merged value carries the default structure.',
    );
  }

  /**
   * The in-memory list is restored after save (postSave contract).
   */
  public function testMergedValueRestoredAfterSave(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-1', 'component' => 'na_leaf'],
    ], [
      'authored-1' => $this->leafProps('AUTHORED'),
    ]);

    // Immediately after save — same objects, no reload — the list must hold
    // the merged value again, not the storage subset preSave() swapped in.
    $value = $entity->get(static::FIELD_NAME)->first()->getValue();
    $tree = json_decode($value['tree'], TRUE);

    $this->assertNotEmpty($tree[ComponentTreeStructure::ROOT_UUID] ?? [], 'The root section is populated — the list holds the merged value, not the subset.');
    $this->assertSame(
      [['uuid' => 'authored-1', 'component' => 'na_leaf']],
      $tree[static::HOST_UUID]['body'] ?? NULL,
    );
  }

  /**
   * An explicitly emptied flagged slot stays empty.
   *
   * Emptying the region is a choice, not an absence: the slot is stored as
   * [] and the default seeds must not come back on the next load or save.
   */
  public function testExplicitlyEmptySlotStaysEmpty(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent($entity, [], []);

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame([], $stored['tree'][static::HOST_UUID]['body'] ?? NULL, 'The emptied slot is stored as explicitly empty.');

    $entity = $this->reloadEntity($entity);
    $merged = json_decode($entity->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertArrayNotHasKey(static::HOST_UUID, $merged, 'No seed content re-enters the merged value.');

    // And it survives another save cycle.
    $entity->save();
    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame([], $stored['tree'][static::HOST_UUID]['body'] ?? NULL);
  }

  /**
   * Nested descendants round-trip, including a props-less container.
   *
   * The container tuple owns its own section; its missing props entry must
   * be backfilled by the parity guard or ComponentTreeItem::preSave() throws
   * a LogicException on save.
   */
  public function testNestedDescendantsRoundTrip(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent($entity, [
      ['uuid' => 'container-1', 'component' => 'na_two_region'],
    ], [
      // container-1 deliberately gets NO props entry.
      'deep-leaf' => $this->leafProps('DEEP AUTHORED'),
    ]);

    // Nest a leaf inside the authored container and resave.
    $entity = $this->reloadEntity($entity);
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'container-1', 'component' => 'na_two_region']];
    $tree['container-1'] = ['top' => [['uuid' => 'deep-leaf', 'component' => 'na_leaf']]];
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => $tree,
        'props' => $defaults['props'] + ['deep-leaf' => $this->leafProps('DEEP AUTHORED')],
      ],
    ]);
    $entity->save();

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame(
      [['uuid' => 'deep-leaf', 'component' => 'na_leaf']],
      $stored['tree']['container-1']['top'] ?? NULL,
      'The nested descendant section persisted.',
    );
    $this->assertSame($this->leafProps('DEEP AUTHORED'), $stored['props']['deep-leaf'] ?? NULL);
    $this->assertArrayHasKey('container-1', $stored['props'], 'The props-less container was backfilled for tree/props parity.');

    // And the reloaded merged value carries the whole chain.
    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame($tree['container-1'], $merged['container-1'] ?? NULL, 'The nested chain survives the reload merge.');
  }

}
