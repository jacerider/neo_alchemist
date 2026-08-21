<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\UserSession;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\EditorState\MemoryEditorStateStore;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * Optimistic conflict detection on the shared draft.
 *
 * A session carries the draft version it loaded; a write whose carried version
 * is behind the stored one is refused rather than allowed to overwrite a
 * colleague's work with a stale copy. A lock was considered and rejected — it
 * turns a rare collision into a routine obstruction, and a held lock outlives
 * the person who left for lunch — so the conflict an editor used to discover at
 * publish time is caught at edit time, and the editor is offered a reload.
 *
 * These tests assert what a second person observes: a write at the current
 * version succeeds and increments it; a stale write is refused and leaves the
 * record — and the other editor's content — untouched; and a reload lets the
 * second person try again. The primary seam is the store, exercised over the
 * in-memory adapter; one test drives the whole-tree draft save through
 * saveComponents() to prove the save path carries the version to the store.
 *
 * @see \Drupal\neo_alchemist\EditorState\SharedDraftStore::set()
 * @see \Drupal\neo_alchemist\EditorState\DraftConflictException
 */
#[Group('neo_alchemist')]
class SharedDraftConflictTest extends HybridFieldKernelTestBase {

  /**
   * A write at the current stored version succeeds and increments it.
   */
  public function testWriteAtCurrentVersionSucceedsAndIncrements(): void {
    $item = $this->fieldItem();
    $store = $this->store(new MemoryEditorStateStore());
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 101]));

    // A draftless item is at version 0; a write carrying 0 is at the current
    // version, so it succeeds and increments to 1.
    $this->assertSame(0, $store->version($item), 'Premise: a draftless item is at version 0.');
    $store->set($item, ['tree' => '{}', 'props' => '[]'], 0);
    $this->assertSame(1, $store->version($item), 'A write at the current version incremented it.');

    // The same session, having reloaded to version 1, writes again.
    $store->set($item, ['tree' => '{"a":1}', 'props' => '[]'], 1);
    $this->assertSame(2, $store->version($item), 'Every write at the current version increments it.');
  }

  /**
   * A stale write is refused and leaves the other editor's content intact.
   *
   * Two sessions against one draft — the test that cannot be written at any
   * level without the store seam. Both loaded the draftless layout at version
   * 0; the first to save wins the version, and the second's stale save is
   * refused rather than silently overwriting the first's work.
   */
  public function testStaleWriteIsRefusedAndLeavesTheRecordIntact(): void {
    $item = $this->fieldItem();
    $store = $this->store(new MemoryEditorStateStore());
    $currentUser = $this->container->get('current_user');

    $draftA = ['tree' => '{"a":1}', 'props' => '[]'];
    $draftB = ['tree' => '{"b":2}', 'props' => '[]'];

    // Session A (user 101) saves first, from the version it loaded (0).
    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $store->set($item, $draftA, 0);
    $this->assertSame(1, $store->version($item), 'Premise: A established version 1.');

    // Session B (user 102) still carries the version it loaded (0). Its save is
    // refused, naming the colleague who moved the draft on.
    $currentUser->setAccount(new UserSession(['uid' => 102]));
    try {
      $store->set($item, $draftB, 0);
      $this->fail('A write behind the stored version should be refused.');
    }
    catch (DraftConflictException $conflict) {
      $this->assertSame(0, $conflict->getExpectedVersion(), 'The refusal reports the version B loaded.');
      $this->assertSame(1, $conflict->getStoredVersion(), 'The refusal reports the version now stored.');
      $this->assertSame(101, $conflict->getLastEditorUid(), 'The refusal names who changed the draft.');
    }

    // The record is untouched: A's content, A the last editor, the version and
    // contributor set unchanged — B's stale copy never landed.
    $this->assertSame($draftA, $store->get($item), "The refused write left A's content intact.");
    $this->assertSame(1, $store->version($item), 'The refused write did not advance the version.');
    $this->assertSame(101, $store->lastEditor($item), 'The refused write did not become the last editor.');
    $this->assertSame([101], $store->contributors($item), 'The refused writer did not join the contributor set.');

    // Offered a reload, session B loads version 1 and saves against it. Now it
    // succeeds — the conflict was caught, not the collaboration.
    $store->set($item, $draftB, 1);
    $this->assertSame(2, $store->version($item), 'A reloaded write at the current version succeeds.');
    $this->assertSame($draftB, $store->get($item), "B's content landed once it wrote against the current version.");
    $this->assertSame(102, $store->lastEditor($item), 'The reloaded write became the last editor.');
    $this->assertSame([101, 102], $store->contributors($item), 'The reloaded write joined the contributor set.');
  }

  /**
   * The publish commit is guarded too: a stale publish is refused.
   *
   * Publishing consumes the draft (delete + persist). A publisher who loaded
   * the draft at an old version must not release a version a colleague has
   * since written — so delete() carries the version and refuses when behind.
   */
  public function testStalePublishCommitIsRefused(): void {
    $item = $this->fieldItem();
    $store = $this->store(new MemoryEditorStateStore());
    $currentUser = $this->container->get('current_user');

    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $store->set($item, ['tree' => '{"a":1}', 'props' => '[]'], 0);
    $this->assertSame(1, $store->version($item), 'Premise: the draft is at version 1.');

    // A publisher who loaded the draft at version 0 tries to commit it while it
    // is now at version 1. The publish is refused and the draft stays put.
    $currentUser->setAccount(new UserSession(['uid' => 102]));
    try {
      $store->delete($item, 0);
      $this->fail('A publish commit behind the stored version should be refused.');
    }
    catch (DraftConflictException $conflict) {
      $this->assertSame(1, $conflict->getStoredVersion(), 'The refusal reports the version now stored.');
      $this->assertSame(101, $conflict->getLastEditorUid(), 'The refusal names who changed the draft.');
    }
    $this->assertTrue($store->has($item), 'The refused publish left the draft in place.');

    // At the current version, the publish commit clears the draft.
    $store->delete($item, 1);
    $this->assertFalse($store->has($item), 'A publish at the current version clears the draft.');
  }

  /**
   * An unguarded write (no carried version) is never refused.
   *
   * NULL is the default and means "not version-checked": the per-user scratch
   * buffer and non-session callers carry no loaded version, so their writes
   * must always land even when a version comparison would call them behind.
   */
  public function testAnUnguardedWriteIsNeverRefused(): void {
    $item = $this->fieldItem();
    $store = $this->store(new MemoryEditorStateStore());

    $store->set($item, ['tree' => '{"a":1}', 'props' => '[]']);
    // A second unguarded write would be "behind" version 1 had it carried 0,
    // but with no carried version it is not checked and simply lands.
    $store->set($item, ['tree' => '{"b":2}', 'props' => '[]']);
    $this->assertSame(2, $store->version($item), 'Unguarded writes still increment the version.');
    $this->assertSame(['tree' => '{"b":2}', 'props' => '[]'], $store->get($item), 'The last unguarded write is stored.');
  }

  /**
   * Only shared-draft writes are guarded; the scratch buffer is not.
   *
   * The asymmetry is structural: the shared draft write takes a version to
   * check, the per-user scratch write does not. Guarding the private buffer
   * would manufacture spurious conflicts against yourself.
   */
  public function testOnlySharedDraftWritesAreVersionChecked(): void {
    $sharedParams = (new \ReflectionMethod(SharedDraftStore::class, 'set'))->getParameters();
    $sharedNames = array_map(static fn ($p) => $p->getName(), $sharedParams);
    $this->assertContains('expectedVersion', $sharedNames, 'The shared draft write carries a version to guard.');

    $scratchParams = (new \ReflectionMethod(EditorScratchStore::class, 'set'))->getParameters();
    $scratchNames = array_map(static fn ($p) => $p->getName(), $scratchParams);
    $this->assertNotContains('expectedVersion', $scratchNames, 'The per-user scratch buffer is not version-checked.');
  }

  /**
   * The whole-tree draft save carries the version through saveComponents().
   *
   * The store guard is only worth anything if the save path hands it the loaded
   * version. This drives two sessions through the real save path — the same
   * mechanism the editor's AJAX save uses — and asserts the stale one is
   * refused without advancing the stored draft.
   */
  public function testSaveComponentsCarriesTheLoadedVersion(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $currentUser = $this->container->get('current_user');
    $store = $this->container->get('neo_alchemist.shared_draft_store');

    // Session A stashes a whole-tree draft; the save establishes version 1.
    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $itemA = $entity->get(static::FIELD_NAME)->first();
    $itemA->enforceAsDraft();
    $itemA->setValue($this->draftedValue('A'));
    $itemA->saveComponents();
    $this->assertSame(1, $store->version($itemA), 'The whole-tree draft save established version 1.');

    // Session B loaded at version 0 and carries it. Its save is refused because
    // the draft moved to version 1 under it.
    $currentUser->setAccount(new UserSession(['uid' => 102]));
    $itemB = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first();
    $itemB->enforceAsDraft();
    $itemB->setValue($this->draftedValue('B'));
    $itemB->carryDraftVersion(0);

    $refused = FALSE;
    try {
      $itemB->saveComponents();
    }
    catch (DraftConflictException $conflict) {
      $refused = TRUE;
      $this->assertSame(101, $conflict->getLastEditorUid(), 'The refusal names the colleague who saved first.');
    }
    $this->assertTrue($refused, 'A stale whole-tree draft save is refused through saveComponents().');
    $this->assertSame(1, $store->version($itemB), 'The refused save did not advance the stored version.');
  }

  /**
   * Builds a distinguishable drafted value for the hybrid region.
   *
   * @param string $marker
   *   A marker folded into the drafted leaf's uuid and text.
   *
   * @return array
   *   The merged ['tree' => array, 'props' => array] value to draft.
   */
  private function draftedValue(string $marker): array {
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'leaf-' . $marker, 'component' => 'na_leaf']];
    return [
      'tree' => $tree,
      'props' => $defaults['props'] + ['leaf-' . $marker => $this->leafProps($marker)],
    ];
  }

  /**
   * Builds a shared draft store over the given adapter, wired to live services.
   */
  private function store(MemoryEditorStateStore $memory): SharedDraftStore {
    return new SharedDraftStore(
      $memory,
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
      $this->container->get('cache_tags.invalidator'),
    );
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
