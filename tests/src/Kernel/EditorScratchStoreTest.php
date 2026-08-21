<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Session\UserSession;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\EditorState\EditorStateStoreInterface;
use Drupal\neo_alchemist\EditorState\MemoryEditorStateStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * The editor-state store seam, proven on the per-user scratch buffer.
 *
 * The seam's whole point is that the sharing semantics of a piece of editor
 * state are a property of the store, not of the arguments a caller passes.
 * These tests assert what a second person observes — whether they see the
 * other's live form buffer — over the in-memory adapter, which is the test
 * injection point the seam exists to provide.
 *
 * @see \Drupal\neo_alchemist\EditorState\EditorScratchStore
 * @see \Drupal\neo_alchemist\EditorState\EditorStateStoreInterface
 */
#[Group('neo_alchemist')]
class EditorScratchStoreTest extends HybridFieldKernelTestBase {

  /**
   * The instance uuid whose live form buffer these tests exercise.
   */
  private const UUID = 'scratch-instance-uuid';

  /**
   * The seam and both adapters are container-registered.
   */
  public function testStoreSeamIsContainerRegistered(): void {
    $tempStore = $this->container->get('neo_alchemist.editor_state.private_tempstore');
    $memory = $this->container->get('neo_alchemist.editor_state.memory');
    $scratch = $this->container->get('neo_alchemist.editor_scratch_store');

    $this->assertInstanceOf(EditorStateStoreInterface::class, $tempStore, 'The production adapter implements the seam.');
    $this->assertInstanceOf(EditorStateStoreInterface::class, $memory, 'The in-memory adapter implements the seam.');
    $this->assertInstanceOf(EditorScratchStore::class, $scratch, 'The scratch store is registered.');
  }

  /**
   * One user's scratch write is invisible to another user.
   *
   * The buffer lives in the per-user scratch store: its key folds in the
   * current user, so two editors working the same instance never see each
   * other's unsaved form values — over any adapter, here the in-memory one.
   */
  public function testScratchWriteIsPrivatePerUser(): void {
    $item = $this->fieldItem();
    $currentUser = $this->container->get('current_user');
    $scratch = new EditorScratchStore(
      new MemoryEditorStateStore(),
      $currentUser,
      $this->container->get('cache_tags.invalidator'),
    );

    $userA = new UserSession(['uid' => 101]);
    $userB = new UserSession(['uid' => 102]);

    // User A buffers values and reads them back.
    $currentUser->setAccount($userA);
    $scratch->set($item, self::UUID, ['props' => ['text' => 'A wrote this']]);
    $this->assertSame(
      ['props' => ['text' => 'A wrote this']],
      $scratch->get($item, self::UUID),
      'User A reads back their own buffered values.',
    );

    // User B sees nothing of A's buffer.
    $currentUser->setAccount($userB);
    $this->assertNull(
      $scratch->get($item, self::UUID),
      "User B cannot see user A's live form buffer.",
    );

    // User B's own buffer is independent.
    $scratch->set($item, self::UUID, ['props' => ['text' => 'B wrote this']]);
    $this->assertSame(
      ['props' => ['text' => 'B wrote this']],
      $scratch->get($item, self::UUID),
      "User B reads back their own buffered values.",
    );

    // A's buffer is untouched by B's write.
    $currentUser->setAccount($userA);
    $this->assertSame(
      ['props' => ['text' => 'A wrote this']],
      $scratch->get($item, self::UUID),
      "User A's buffer survives user B writing the same instance.",
    );

    // A delete clears only the current user's buffer.
    $scratch->delete($item, self::UUID);
    $this->assertNull($scratch->get($item, self::UUID), 'User A deleted their own buffer.');
    $currentUser->setAccount($userB);
    $this->assertSame(
      ['props' => ['text' => 'B wrote this']],
      $scratch->get($item, self::UUID),
      "User A's delete left user B's buffer intact.",
    );
  }

  /**
   * Writing and clearing the buffer invalidates the preview's cache tag.
   *
   * Invalidation is a postcondition of the store's write and delete, so no
   * caller can ship a stale preview by forgetting a line — the structural
   * guarantee the seam exists to make.
   */
  public function testWriteInvalidatesPreviewCacheTag(): void {
    $item = $this->fieldItem();
    $currentUser = $this->container->get('current_user');
    $currentUser->setAccount(new UserSession(['uid' => 101]));

    $recorded = [];
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(
      function (array $tags) use (&$recorded): void {
        $recorded = array_merge($recorded, $tags);
      },
    );

    $scratch = new EditorScratchStore(new MemoryEditorStateStore(), $currentUser, $invalidator);
    $tag = $scratch->cacheTag($item, self::UUID);

    $scratch->set($item, self::UUID, ['props' => ['text' => 'x']]);
    $this->assertContains($tag, $recorded, 'Writing the buffer invalidated the preview cache tag.');

    $recorded = [];
    $scratch->delete($item, self::UUID);
    $this->assertContains($tag, $recorded, 'Clearing the buffer invalidated the preview cache tag.');
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
