<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\UserSession;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\EditorState\EditorHistory;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\EditorState\MemoryEditorStateStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * Per-user undo and redo over the shared layout draft.
 *
 * Every write goes through saveComponents() on a detached copy of the field,
 * the way the editor's routes hand it out, so the history is recorded by the
 * save path itself rather than by the test. The history lives in a scratch
 * store over the in-memory adapter, swapped in for the container's.
 *
 * @see \Drupal\neo_alchemist\EditorState\EditorHistory
 */
#[Group('neo_alchemist')]
class EditorHistoryTest extends HybridFieldKernelTestBase {

  /**
   * The history under test.
   */
  private EditorHistory $history;

  /**
   * The entity whose layout is edited.
   */
  private EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $scratch = new EditorScratchStore(
      new MemoryEditorStateStore(),
      $this->container->get('current_user'),
      $this->container->get('cache_tags.invalidator'),
    );
    $this->history = new EditorHistory(
      $this->container->get('neo_alchemist.shared_draft_store'),
      $scratch,
      $this->container->get('plugin.manager.neo_alchemist_panel'),
    );
    $this->container->set('neo_alchemist.editor_history', $this->history);
    $this->entity = $this->createTestEntity();
    $this->actAs(101);
  }

  /**
   * Undo takes back the last write, redo puts it back, both named.
   */
  public function testUndoAndRedoStepThroughWrites(): void {
    $this->write('A', 'Add leaf A', 'leaf-A');
    $this->write('B', 'Edit leaf B', 'leaf-B');
    $this->assertSame('Edit leaf B', $this->history->state($this->item())['undo'], 'Undo names the last step.');

    $step = $this->history->undo($this->item());
    $this->assertSame('Edit leaf B', $step['label'], 'Undo reports the step it took back.');
    $this->assertSame('A', $this->draftedMarker(), 'Undo restored the layout before the last write.');
    $this->assertSame('Edit leaf B', $this->history->state($this->item())['redo'], 'Redo names the step undo took back.');

    $step = $this->history->redo($this->item());
    $this->assertSame('Edit leaf B', $step['label'], 'Redo reports the step it put back.');
    $this->assertSame('leaf-B', $step['uuid'], 'Redo selects the component the step acted on.');
    $this->assertSame('B', $this->draftedMarker(), 'Redo restored the layout the undo replaced.');
  }

  /**
   * A write through the entity's own field item is still measured from storage.
   *
   * A form round-trip rebuilds the item on the entity itself, so the entity in
   * memory already carries the edit: compared with it, the write looked like
   * no change and was no step.
   */
  public function testWriteThroughEntityItemCountsAsStep(): void {
    $item = $this->reloadEntity($this->entity)->get(static::FIELD_NAME)->first();
    $item->enforceAsDraft();
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'leaf-A', 'component' => 'na_leaf']];
    $item->setValue(['tree' => $tree, 'props' => $defaults['props'] + ['leaf-A' => $this->leafProps('A')]]);
    $item->describeStep('Edit leaf A');
    $item->saveComponents();
    $this->assertSame('Edit leaf A', $this->history->state($this->item())['undo'], 'The write is a step.');
  }

  /**
   * A step whose component the restored layout lacks selects nothing.
   */
  public function testUndoingAnAddSelectsNothing(): void {
    $this->write('A', 'Add leaf A', 'leaf-A');
    $this->write('B', 'Add leaf B', 'leaf-B');
    $step = $this->history->undo($this->item());
    $this->assertNull($step['uuid'], 'The undone add left nothing to select.');
  }

  /**
   * A new step after an undo forgets what could have been redone.
   */
  public function testNewStepClearsRedo(): void {
    $this->write('A', 'Add leaf A');
    $this->write('B', 'Edit leaf B');
    $this->history->undo($this->item());
    $this->write('C', 'Edit leaf C');
    $this->assertNull($this->history->state($this->item())['redo'], 'A new step cleared redo.');
    $this->assertNull($this->history->redo($this->item()), 'Redo has nothing to do.');
  }

  /**
   * A write that changes nothing is not a step.
   */
  public function testUnchangedWriteIsNoStep(): void {
    $this->write('A', 'Add leaf A');
    $this->write('A', 'Edit leaf A');
    $this->assertSame('Add leaf A', $this->history->state($this->item())['undo'], 'The unchanged write left no step.');
    $this->history->undo($this->item());
    $this->assertFalse($this->item()->hasDraft(), 'One undo took back the one real step.');
  }

  /**
   * Fifty steps are kept; the oldest go.
   */
  public function testHistoryIsCapped(): void {
    for ($i = 1; $i <= EditorHistory::CAP + 5; $i++) {
      $this->write((string) $i, "Step $i");
    }
    $undone = 0;
    while ($this->history->undo($this->item())) {
      $undone++;
    }
    $this->assertSame(EditorHistory::CAP, $undone, 'Only the newest fifty steps can be undone.');
    $this->assertSame('5', $this->draftedMarker(), 'The last undo lands on the layout before the oldest kept step.');
  }

  /**
   * Undoing back to the saved layout deletes the draft; redo brings it back.
   */
  public function testUndoBackToSavedDeletesTheDraft(): void {
    $this->write('A', 'Add leaf A');
    $this->assertTrue($this->item()->hasDraft(), 'Premise: the write drafted.');

    $this->history->undo($this->item());
    $this->assertFalse($this->item()->hasDraft(), 'Back at the saved layout, the draft is gone.');

    $this->history->redo($this->item());
    $this->assertTrue($this->item()->hasDraft(), 'Redo drafted again.');
    $this->assertSame('A', $this->draftedMarker(), 'Redo recreated the draft it undid.');
  }

  /**
   * A panel's drafted edit keeps the draft, and undo never touches it.
   */
  public function testUndoKeepsPanelEdits(): void {
    $drafts = $this->container->get('neo_alchemist.shared_draft_store');
    $this->write('A', 'Add leaf A');
    $this->panelWrite(['label' => 'Kept']);

    $this->history->undo($this->item());
    $this->assertTrue($this->item()->hasDraft(), 'With a panel edit drafted, undo to the saved layout keeps the draft.');
    $this->assertSame(['na_test_label' => ['label' => 'Kept']], $drafts->panels($this->item()), 'Undo left the panel data alone.');
    $this->assertNull($this->draftedMarker(), 'The layout is back to the saved one.');
  }

  /**
   * What a panel only computed does not keep the draft.
   */
  public function testComputedPanelDataGoesWithTheDraft(): void {
    $this->write('A', 'Add leaf A');
    $this->panelWrite(['seen' => 3]);

    $this->history->undo($this->item());
    $this->assertFalse($this->item()->hasDraft(), 'Computed panel data alone did not keep the draft.');
  }

  /**
   * A colleague's write refuses undo, naming them, and clears the history.
   */
  public function testColleagueWriteRefusesUndo(): void {
    $this->write('A', 'Add leaf A');
    $this->actAs(102);
    $this->write('B', 'Edit leaf B');

    $this->actAs(101);
    $this->assertSame(102, $this->history->state($this->item())['refused'], 'The buttons say who wrote in between.');
    try {
      $this->history->undo($this->item());
      $this->fail('Undo over a colleague\'s write should be refused.');
    }
    catch (DraftConflictException $conflict) {
      $this->assertSame(102, $conflict->getLastEditorUid(), 'The refusal names the colleague.');
    }
    $this->assertSame('B', $this->draftedMarker(), "The colleague's work is intact.");
    $this->assertSame(['undo' => NULL, 'redo' => NULL, 'refused' => NULL], $this->history->state($this->item()), 'The refused history is gone.');

    $this->actAs(102);
    $this->assertSame('Edit leaf B', $this->history->state($this->item())['undo'], "The colleague's own history is theirs.");
  }

  /**
   * Save clears the history; a draft deleted from under it refuses undo.
   */
  public function testSaveAndRevertEndTheHistory(): void {
    $this->write('A', 'Add leaf A');
    $item = $this->item();
    $item->enforceAsDraft(FALSE);
    $item->saveComponents();
    $this->assertSame(['undo' => NULL, 'redo' => NULL, 'refused' => NULL], $this->history->state($this->item()), 'Save cleared the history.');

    $this->write('B', 'Edit leaf B');
    // Revert deletes the draft, whoever asks.
    $this->container->get('neo_alchemist.shared_draft_store')->delete($this->item());
    $this->expectException(DraftConflictException::class);
    $this->history->undo($this->item());
  }

  /**
   * Drafts a layout marked with $marker, as the editor's routes would.
   */
  private function write(string $marker, string $label, ?string $uuid = NULL): void {
    $item = $this->item();
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'leaf-' . $marker, 'component' => 'na_leaf']];
    $item->setValue([
      'tree' => $tree,
      'props' => $defaults['props'] + ['leaf-' . $marker => $this->leafProps($marker)],
    ]);
    $item->describeStep($label, $uuid);
    $item->saveComponents();
  }

  /**
   * Writes the test panel's draft key as this user, as the panel route does.
   */
  private function panelWrite(array $data): void {
    $drafts = $this->container->get('neo_alchemist.shared_draft_store');
    $item = $this->item();
    $version = $drafts->version($item);
    $drafts->setPanel($item, 'na_test_label', $data);
    $this->history->noteWrite($item, $version);
  }

  /**
   * The field item, on a detached copy of the list as the param converter has.
   */
  private function item(): ComponentTreeItem {
    $list = clone $this->reloadEntity($this->entity)->get(static::FIELD_NAME);
    $item = $list->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item->enforceAsDraft();
  }

  /**
   * The marker of the drafted leaf, or NULL when none is drafted.
   */
  private function draftedMarker(): ?string {
    $value = $this->container->get('neo_alchemist.shared_draft_store')->get($this->item());
    if ($value === NULL) {
      return NULL;
    }
    $tree = is_string($value['tree']) ? Json::decode($value['tree']) : $value['tree'];
    foreach ($tree[static::HOST_UUID]['body'] ?? [] as $leaf) {
      if (str_starts_with($leaf['uuid'], 'leaf-')) {
        return substr($leaf['uuid'], 5);
      }
    }
    return NULL;
  }

  /**
   * Switches the current user.
   */
  private function actAs(int $uid): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => $uid]));
  }

}
