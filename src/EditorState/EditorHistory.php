<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Component\Serialization\Json;
use Drupal\neo_alchemist\Panel\AlchemistPanelManager;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Undo and redo over the shared layout draft, per user.
 *
 * A step is one layout draft write — an add, a delete, a clone, a move, an
 * inspector Apply — kept as a whole-tree snapshot: the value the layout had
 * just before it. Undoing writes that value back; redoing writes the value
 * the undo replaced. Snapshots rather than inverse operations, because the
 * draft is one record with one version, and a snapshot restores exactly
 * whatever any write did.
 *
 * The history is the current user's (EditorScratchStore), so it survives a
 * reload and never mixes with a colleague's. It holds the draft version this
 * user's last write left. A version that has moved on since means somebody
 * else wrote the draft: undoing would throw their work away, so it is refused
 * and the history goes (the same refusal family as a stale save,
 * DraftConflictException). Writes of this user's that are not steps — an
 * editor panel's (Search & social) — move the version on without one.
 *
 * Restoring the saved layout itself deletes the draft rather than writing a
 * copy of it, so the editor goes quiet, unless a panel has drafted an edit
 * beside the layout, which the delete would throw away. What a panel only
 * computed (a score) goes with the draft.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::saveComponents()
 */
final class EditorHistory {

  /**
   * Steps kept per direction; the oldest go first.
   */
  public const CAP = 50;

  /**
   * Constructs the history.
   *
   * @param \Drupal\neo_alchemist\EditorState\SharedDraftStore $drafts
   *   The shared draft store, which the steps write through.
   * @param \Drupal\neo_alchemist\EditorState\EditorScratchStore $scratch
   *   The per-user store the history is kept in.
   * @param \Drupal\neo_alchemist\Panel\AlchemistPanelManager $panels
   *   The editor panels, which say whether their drafted data is an edit.
   */
  public function __construct(
    protected readonly SharedDraftStore $drafts,
    protected readonly EditorScratchStore $scratch,
    protected readonly AlchemistPanelManager $panels,
  ) {}

  /**
   * Records a layout draft write as one step.
   *
   * Called with what the layout was and what the draft's version was just
   * before the write, after the write has landed.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array $before
   *   The layout value just before the write.
   * @param int $versionBefore
   *   The draft version just before the write.
   * @param string $label
   *   What the step did, as Undo and Redo will name it ("Delete Hero S5").
   * @param string|null $uuid
   *   The component the step acted on, selected again after undo and redo.
   */
  public function record(ComponentTreeItem $item, array $before, int $versionBefore, string $label, ?string $uuid = NULL): void {
    if ($this->sameLayout($before, $this->drafts->get($item) ?? [])) {
      // An Apply that changed nothing is no step, but the version moved on.
      $this->noteWrite($item, $versionBefore);
      return;
    }
    $history = $this->current($item, $versionBefore);
    $history['undo'][] = ['label' => $label, 'value' => $before, 'uuid' => $uuid];
    $history['undo'] = array_slice($history['undo'], -self::CAP);
    $history['redo'] = [];
    $history['version'] = $this->drafts->version($item);
    $this->scratch->setHistory($item, $history);
  }

  /**
   * Notes a write of this user's that is not a step (an editor panel's).
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param int $versionBefore
   *   The draft version just before the write.
   */
  public function noteWrite(ComponentTreeItem $item, int $versionBefore): void {
    $history = $this->current($item, $versionBefore);
    if ($history['undo'] || $history['redo']) {
      $history['version'] = $this->drafts->version($item);
      $this->scratch->setHistory($item, $history);
    }
  }

  /**
   * What Undo and Redo would do now.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array
   *   `undo` and `redo`: the label of the step each would take, or NULL.
   *   `refused`: the user id of the colleague who wrote the draft since this
   *   user's last write (both are then unavailable), or NULL; 0 for a writer
   *   the draft does not name.
   */
  public function state(ComponentTreeItem $item): array {
    $history = $this->scratch->history($item);
    if (($history['undo'] || $history['redo']) && $history['version'] !== $this->drafts->version($item)) {
      return ['undo' => NULL, 'redo' => NULL, 'refused' => $this->drafts->lastEditor($item) ?? 0];
    }
    return [
      'undo' => $history['undo'] ? end($history['undo'])['label'] : NULL,
      'redo' => $history['redo'] ? end($history['redo'])['label'] : NULL,
      'refused' => NULL,
    ];
  }

  /**
   * Undoes the last step.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array|null
   *   The step undone (`label`, and the `uuid` to select, NULL when the
   *   restored layout no longer has that component), or NULL when there was
   *   none.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When a colleague wrote the draft since this user's last write. The
   *   history is cleared first.
   */
  public function undo(ComponentTreeItem $item): ?array {
    return $this->step($item, 'undo', 'redo');
  }

  /**
   * Redoes the last undone step.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array|null
   *   The step redone (`label`, and the `uuid` to select, NULL when the
   *   restored layout no longer has that component), or NULL when there was
   *   none.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When a colleague wrote the draft since this user's last write. The
   *   history is cleared first.
   */
  public function redo(ComponentTreeItem $item): ?array {
    return $this->step($item, 'redo', 'undo');
  }

  /**
   * Forgets this user's history: on Save, Revert and Reset.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   */
  public function clear(ComponentTreeItem $item): void {
    $this->scratch->clearHistory($item);
  }

  /**
   * Takes a step off one stack, applies it, and files the reverse on the other.
   */
  private function step(ComponentTreeItem $item, string $from, string $to): ?array {
    $history = $this->scratch->history($item);
    if (!$history[$from]) {
      return NULL;
    }
    $version = $this->drafts->version($item);
    if ($history['version'] !== $version) {
      $this->clear($item);
      throw new DraftConflictException($history['version'], $version, $this->drafts->lastEditor($item));
    }
    $step = array_pop($history[$from]);
    $saved = $item->savedValue();
    $now = $this->drafts->get($item) ?? $saved;
    if ($this->sameLayout($step['value'], $saved) && !$this->panels->edited($item, $this->drafts->panels($item))) {
      $this->drafts->delete($item, $version);
    }
    else {
      $this->drafts->set($item, $step['value'], $version);
    }
    $history[$to][] = ['label' => $step['label'], 'value' => $now, 'uuid' => $step['uuid']];
    $history[$to] = array_slice($history[$to], -self::CAP);
    $history['version'] = $this->drafts->version($item);
    $this->scratch->setHistory($item, $history);
    // Only a component the restored layout has can be selected again: undoing
    // an add takes it away.
    $uuid = $step['uuid'] !== NULL && str_contains($this->encodedTree($step['value']), $step['uuid']) ? $step['uuid'] : NULL;
    return ['label' => $step['label'], 'uuid' => $uuid];
  }

  /**
   * A layout value's tree, as the JSON it is stored as.
   */
  private function encodedTree(array $value): string {
    $tree = $value['tree'] ?? [];
    return is_string($tree) ? $tree : Json::encode($tree);
  }

  /**
   * The history as of a write that started at $versionBefore.
   *
   * A history whose version is not that one was overtaken by a colleague's
   * write, so it is dropped.
   */
  private function current(ComponentTreeItem $item, int $versionBefore): array {
    $history = $this->scratch->history($item);
    if (($history['undo'] || $history['redo']) && $history['version'] !== $versionBefore) {
      $this->clear($item);
      return ['undo' => [], 'redo' => [], 'version' => $versionBefore];
    }
    return $history;
  }

  /**
   * Whether two layout values are the same layout.
   *
   * The tree and props travel as JSON strings or as decoded arrays depending
   * on who wrote them, so both are compared decoded.
   */
  private function sameLayout(array $a, array $b): bool {
    $decode = static function (array $value): array {
      foreach (['tree', 'props'] as $key) {
        if (isset($value[$key]) && is_string($value[$key])) {
          $value[$key] = Json::decode($value[$key]);
        }
        $value[$key] ??= [];
      }
      return ['tree' => $value['tree'], 'props' => $value['props']];
    };
    return $decode($a) == $decode($b);
  }

}
