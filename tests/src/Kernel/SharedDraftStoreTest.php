<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Session\UserSession;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\EditorState\EditorStateStoreInterface;
use Drupal\neo_alchemist\EditorState\MemoryEditorStateStore;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shared draft store, and the key-space split it declares.
 *
 * The whole point of the two stores is that the sharing semantics of a piece
 * of editor state are a property of the store you reached for, not of an
 * argument you passed or a backend you happened to hit. These tests assert what
 * a second person observes over the in-memory adapter — the shared draft is
 * visible to them, the scratch buffer is not — with both stores backed by the
 * SAME adapter, so what is being tested is the key space, not the storage.
 *
 * @see \Drupal\neo_alchemist\EditorState\SharedDraftStore
 * @see \Drupal\neo_alchemist\EditorState\EditorScratchStore
 */
#[Group('neo_alchemist')]
class SharedDraftStoreTest extends HybridFieldKernelTestBase {

  /**
   * The instance uuid whose scratch buffer the separation test exercises.
   */
  private const UUID = 'scratch-instance-uuid';

  /**
   * The durable adapter and the shared draft store are container-registered.
   */
  public function testStoreIsContainerRegistered(): void {
    $state = $this->container->get('neo_alchemist.editor_state.state');
    $shared = $this->container->get('neo_alchemist.shared_draft_store');

    $this->assertInstanceOf(EditorStateStoreInterface::class, $state, 'The durable adapter implements the seam.');
    $this->assertInstanceOf(SharedDraftStore::class, $shared, 'The shared draft store is registered.');
  }

  /**
   * A shared draft is visible across users; a scratch buffer is not.
   *
   * Both halves in one test, over one in-memory adapter: the shared draft store
   * keys without a user segment, so a write is visible to every editor; the
   * scratch store folds the current user in, so a write is private. Asserting
   * both against the same backend is what pins the tiers apart and stops a
   * future change collapsing them back onto one key function.
   */
  public function testSharedDraftIsVisibleAcrossUsersButScratchIsNot(): void {
    $item = $this->fieldItem();
    $currentUser = $this->container->get('current_user');
    $invalidator = $this->container->get('cache_tags.invalidator');

    // Both stores share ONE adapter — segmentation is the key space, not the
    // backend.
    $memory = new MemoryEditorStateStore();
    $shared = new SharedDraftStore($memory, $invalidator);
    $scratch = new EditorScratchStore($memory, $currentUser, $invalidator);

    $userA = new UserSession(['uid' => 101]);
    $userB = new UserSession(['uid' => 102]);

    $draft = ['tree' => '{"root":[]}', 'props' => '[]'];
    $buffer = ['props' => ['text' => 'A is typing']];

    // User A writes both a shared draft and a private scratch buffer.
    $currentUser->setAccount($userA);
    $shared->set($item, $draft);
    $scratch->set($item, self::UUID, $buffer);

    // User B sees the shared draft — it is the collaborative artifact.
    $currentUser->setAccount($userB);
    $this->assertSame(
      $draft,
      $shared->get($item),
      "User B sees user A's shared draft — the layout is shared by design.",
    );
    $this->assertTrue($shared->has($item), 'The shared draft exists for user B too.');

    // But user B sees nothing of user A's live form buffer.
    $this->assertNull(
      $scratch->get($item, self::UUID),
      "User B cannot see user A's live form buffer — the buffer is per-user.",
    );
  }

  /**
   * Writing and clearing the draft invalidates the preview's cache tag.
   *
   * Invalidation is a postcondition of the store's write and delete, so no
   * caller can ship a stale preview by forgetting a line — the structural
   * guarantee that answers the field item's own documented warning about the
   * dynamic page cache serving a pre-edit render indefinitely.
   */
  public function testWriteInvalidatesDraftCacheTag(): void {
    $item = $this->fieldItem();

    $recorded = [];
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(
      function (array $tags) use (&$recorded): void {
        $recorded = array_merge($recorded, $tags);
      },
    );

    $shared = new SharedDraftStore(new MemoryEditorStateStore(), $invalidator);
    $tag = $shared->cacheTag($item);

    $shared->set($item, ['tree' => '{}', 'props' => '[]']);
    $this->assertContains($tag, $recorded, 'Writing the draft invalidated the preview cache tag.');

    $recorded = [];
    $shared->delete($item);
    $this->assertContains($tag, $recorded, 'Clearing the draft invalidated the preview cache tag.');
  }

  /**
   * The draft key and cache-tag derivation are private to the store.
   *
   * A public key accessor is an invitation: the field item used to expose both
   * its key derivation and its cache tag, and two callers reached past it to
   * perform their own draft I/O against the wrong backend. With the derivation
   * gone from the item, no new caller can construct a key and bypass the store.
   * The draft-mode flag and the read-through predicate stay, as the meeting
   * point with the editor and the access checks.
   */
  public function testDraftDerivationIsPrivateToTheStore(): void {
    foreach (['getDraftKey', 'getDraftCacheTag', 'getDraftValue', 'setDraftValue', 'deleteDraft'] as $method) {
      $this->assertFalse(
        method_exists(ComponentTreeItem::class, $method),
        sprintf('%s() no longer exists on the field item; draft storage lives behind the store.', $method),
      );
    }

    foreach (['isDraft', 'enforceAsDraft', 'hasDraft'] as $method) {
      $this->assertTrue(
        method_exists(ComponentTreeItem::class, $method),
        sprintf('%s() stays on the field item.', $method),
      );
    }
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
