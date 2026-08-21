<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\UserSession;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shared draft record's metadata: version, last editor, contributors.
 *
 * Presence, optimistic conflict detection and publish attribution all read one
 * record rather than inferring state or standing up separate infrastructure.
 * These tests assert the data-level contract those three features consume: a
 * write increments the version, records the writing user as last editor, and
 * accumulates the contributor set — and clearing the record (what publish,
 * discard and revert do) clears the contributors with it, so a fresh draft
 * starts with just its author.
 *
 * @see \Drupal\neo_alchemist\EditorState\SharedDraftStore
 */
#[Group('neo_alchemist')]
class SharedDraftMetadataTest extends HybridFieldKernelTestBase {

  /**
   * A write increments the version and records last editor and contributors.
   */
  public function testWritesIncrementVersionAndAccumulateContributors(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    // A fresh entity has no draft, so no metadata yet.
    $this->assertSame(0, $store->version($item), 'A draftless item is at version 0.');
    $this->assertNull($store->lastEditor($item), 'A draftless item has no last editor.');
    $this->assertNull($store->lastModified($item), 'A draftless item has no last-modified time.');
    $this->assertSame([], $store->contributors($item), 'A draftless item has no contributors.');

    // User 101 writes: version 1, last editor and sole contributor.
    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $store->set($item, ['tree' => '{}', 'props' => '[]']);
    $this->assertSame(1, $store->version($item), 'The first write is version 1.');
    $this->assertSame(101, $store->lastEditor($item), 'The writing user is the last editor.');
    $this->assertSame([101], $store->contributors($item), 'The writing user joins the contributor set.');
    $this->assertNotNull($store->lastModified($item), 'The write records a last-modified time.');

    // The same user writes again: version bumps, contributor set is unchanged.
    $store->set($item, ['tree' => '{"a":1}', 'props' => '[]']);
    $this->assertSame(2, $store->version($item), 'Every write increments the version.');
    $this->assertSame(101, $store->lastEditor($item), 'The repeat writer stays the last editor.');
    $this->assertSame([101], $store->contributors($item), 'A repeat writer is not added twice.');

    // A second user writes: version bumps, they become last editor, and they
    // join the contributor set alongside the first.
    $currentUser->setAccount(new UserSession(['uid' => 102]));
    $store->set($item, ['tree' => '{"b":2}', 'props' => '[]']);
    $this->assertSame(3, $store->version($item), 'A colleague writing still increments the version.');
    $this->assertSame(102, $store->lastEditor($item), 'The last editor follows the most recent writer.');
    $this->assertSame([101, 102], $store->contributors($item), 'Both writers accumulate in the contributor set.');
  }

  /**
   * Clearing the record clears the contributor set with it.
   *
   * Publish, discard and revert all reach SharedDraftStore::delete(); because
   * the metadata rides in the one record blob, deleting it clears the version,
   * last editor and contributor set together. A fresh draft then starts with
   * just its author, so publish never names a contributor from a released
   * cycle.
   */
  public function testClearingTheRecordClearsTheContributorSet(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $store->set($item, ['tree' => '{}', 'props' => '[]']);
    $currentUser->setAccount(new UserSession(['uid' => 102]));
    $store->set($item, ['tree' => '{"b":2}', 'props' => '[]']);
    $this->assertSame([101, 102], $store->contributors($item), 'Premise: two contributors accumulated.');

    // Publish/discard/revert all delete the record.
    $store->delete($item);
    $this->assertSame(0, $store->version($item), 'Deleting resets the version.');
    $this->assertNull($store->lastEditor($item), 'Deleting clears the last editor.');
    $this->assertNull($store->lastModified($item), 'Deleting clears the last-modified time.');
    $this->assertSame([], $store->contributors($item), 'Deleting clears the contributor set.');
    $this->assertNull($store->get($item), 'Deleting clears the draft content.');

    // A fresh draft starts with just its author — no carry-over from the cycle
    // that was published.
    $currentUser->setAccount(new UserSession(['uid' => 103]));
    $store->set($item, ['tree' => '{"c":3}', 'props' => '[]']);
    $this->assertSame(1, $store->version($item), 'A fresh draft restarts at version 1.');
    $this->assertSame([103], $store->contributors($item), 'A fresh draft starts with just its author.');
  }

  /**
   * The stored draft content still round-trips unchanged behind the metadata.
   *
   * The metadata wraps the content in a record, but get() must keep returning
   * exactly the content that was set — enforceAsDraft() and the callers that
   * hydrate a draft depend on it.
   */
  public function testDraftContentRoundTripsBehindTheMetadata(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();

    $draft = ['tree' => '{"root":[]}', 'props' => '[]'];
    $store->set($item, $draft);
    $this->assertSame($draft, $store->get($item), 'The draft content is returned unchanged behind its metadata.');
    $this->assertTrue($store->has($item), 'A written draft exists.');
  }

  /**
   * The container-wired store records metadata (current user + time) live.
   */
  private function sharedDraftStore(): SharedDraftStore {
    $store = $this->container->get('neo_alchemist.shared_draft_store');
    $this->assertInstanceOf(SharedDraftStore::class, $store, 'Premise: the shared draft store is registered.');
    return $store;
  }

  /**
   * Resolves a real ComponentTreeItem from a fresh test entity.
   */
  private function fieldItem(): ComponentTreeItem {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item, 'Premise: the entity resolved a component tree item.');
    return $item;
  }

}
