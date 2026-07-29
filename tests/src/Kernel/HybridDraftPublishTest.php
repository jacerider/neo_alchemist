<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * The July 2026 hybrid publish bug, pinned.
 *
 * Publishing silently no-oped with a success message: every editor route
 * carries neo_draft, param conversion re-runs re-entrantly (access checks,
 * path validation, sub-requests), and each re-run called enforceAsDraft() on
 * the SHARED field item — flipping the publish flow's enforceAsDraft(FALSE)
 * back to draft mid-save, so saveComponents() re-stashed the draft instead
 * of persisting it. The fix is two-part: FieldParamConverter works on a
 * detached clone per conversion, and saveComponents() syncs the detached
 * copy's value onto the entity before saving.
 *
 * No HTTP here — the converter service is called directly, which is exactly
 * what the routing layer does.
 *
 * Red/green proof performed during development: with the detached copy
 * removed (convert() returning the shared list),
 * testReentrantConversionDoesNotReenableDraftOnCommittingItem goes red; with
 * the saveComponents() value sync removed, testPublishReachesEntityStorage
 * goes red.
 *
 * @see \Drupal\neo_alchemist\Routing\FieldParamConverter::convert()
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::saveComponents()
 */
#[Group('neo_alchemist')]
class HybridDraftPublishTest extends HybridFieldKernelTestBase {

  /**
   * Converts the entity's field the way the routing layer does.
   */
  private function convertFieldItem(EntityTest $entity, bool $draft = TRUE): ComponentTreeItem {
    $defaults = [
      'entity_type_id' => 'entity_test',
      'entity_test' => $entity,
      'neo_field' => 'alch',
      'neo_draft' => $draft,
    ];
    $item = $this->container->get('neo_alchemist.field_param_converter')
      ->convert('alch', ['type' => 'neo_alchemist_field'], 'neo_field', $defaults);
    $this->assertInstanceOf(ComponentTreeItem::class, $item, 'Premise: the converter resolved the field item.');
    return $item;
  }

  /**
   * The merged item value carrying one drafted leaf in the region.
   */
  private function draftedValue(): array {
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'drafted-leaf', 'component' => 'na_leaf']];
    return [
      'tree' => $tree,
      'props' => $defaults['props'] + ['drafted-leaf' => $this->leafProps('DRAFTED')],
    ];
  }

  /**
   * A draft save goes to state, not to entity storage.
   */
  public function testDraftStashGoesToStateNotStorage(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $item = $this->convertFieldItem($entity);
    $this->assertTrue($item->isDraft(), 'Premise: the route default enforced draft mode.');

    $item->setValue($this->draftedValue());
    $item->saveComponents();

    $this->assertTrue($item->hasDraft(), 'The draft was stashed.');
    $this->assertNull($this->rawStoredValue($this->reloadEntity($entity)), 'Entity storage was not touched by a draft save.');

    // A later conversion re-loads the stashed draft into its own copy.
    $again = $this->convertFieldItem($entity);
    $tree = json_decode($again->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'drafted-leaf', 'component' => 'na_leaf']],
      $tree[static::HOST_UUID]['body'] ?? NULL,
      'A fresh conversion carries the stashed draft.',
    );
  }

  /**
   * A re-entrant conversion cannot re-enable draft on the committing item.
   *
   * This is the bug itself: pre-fix, conversion returned the shared item, so
   * the re-entrant run flipped the committing item back to draft and publish
   * silently re-stashed instead of saving — while the UI reported success.
   */
  public function testReentrantConversionDoesNotReenableDraftOnCommittingItem(): void {
    $entity = $this->createTestEntity();

    // A creator stashes a draft.
    $draftItem = $this->convertFieldItem($entity);
    $draftItem->setValue($this->draftedValue());
    $draftItem->saveComponents();

    // The publish request: a fresh conversion, then the commit flag.
    $publishItem = $this->convertFieldItem($entity);
    $publishItem->enforceAsDraft(FALSE);

    // Something re-enters param conversion on the same route mid-save —
    // an access check, path validation, a sub-request.
    $this->convertFieldItem($entity);

    $this->assertFalse($publishItem->isDraft(), 'A re-entrant param conversion re-enabled draft mode on the committing item; publish would silently no-op.');
  }

  /**
   * Publish persists the drafted content and clears the draft.
   */
  public function testPublishReachesEntityStorage(): void {
    $entity = $this->createTestEntity();

    $draftItem = $this->convertFieldItem($entity);
    $draftItem->setValue($this->draftedValue());
    $draftItem->saveComponents();

    // Publish.
    $publishItem = $this->convertFieldItem($entity);
    $publishItem->enforceAsDraft(FALSE);
    $this->convertFieldItem($entity);
    $publishItem->saveComponents();

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertNotNull($stored, 'The committed draft reached entity storage.');
    $this->assertSame(
      [['uuid' => 'drafted-leaf', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['body'] ?? NULL,
      'The published region content is what was drafted.',
    );
    $this->assertSame($this->leafProps('DRAFTED'), $stored['props']['drafted-leaf'] ?? NULL);

    $fresh = $this->convertFieldItem($this->reloadEntity($entity), FALSE);
    $this->assertFalse($fresh->hasDraft(), 'The draft state was cleared by the publish.');
  }

  /**
   * A conversion's draft value never bleeds into the entity's own list.
   */
  public function testDraftValueDoesNotBleedIntoEntityList(): void {
    $entity = $this->createTestEntity();

    $draftItem = $this->convertFieldItem($entity);
    $draftItem->setValue($this->draftedValue());
    $draftItem->saveComponents();

    // The statically-cached entity keeps its canonical (default) value.
    $tree = json_decode($entity->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => static::SEED_UUID, 'component' => 'na_leaf']],
      $tree[static::HOST_UUID]['body'] ?? NULL,
      'The entity list still renders the default seed — the draft lives only on the detached copy and in state.',
    );
  }

}
