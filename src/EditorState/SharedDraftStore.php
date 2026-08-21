<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * The shared draft store: the collaborative layout draft.
 *
 * A layout draft is the artifact two editors build together — keyed by entity
 * and field with NO user segment, held in durable storage, and published as a
 * whole. That is deliberate: per-user drafts would fork the layout and the last
 * person to publish would silently discard the other's work. This store
 * declares that model rather than leaving it to be inferred from whether a
 * caller passed a component instance uuid to a shared key function.
 *
 * The sharing semantics are a property of the store, not of an argument or a
 * backend: the key derivation folds in no user, so a write is visible to every
 * editor over any adapter — production (durable state) or the in-memory adapter
 * used in tests. Its counterpart, the per-user EditorScratchStore, folds the
 * current user in; the two use different key prefixes, so one key function can
 * never again serve two contradictory policies.
 *
 * The key derivation and the cache-tag derivation are private to the store, so
 * no external caller can construct a key and bypass the store to perform its
 * own draft I/O — the failure the field item documented at length, because the
 * dynamic page cache keeps serving a pre-edit preview when a caller forgets to
 * invalidate. Here invalidation is a postcondition of every write and delete,
 * so it cannot be omitted.
 *
 * The record also carries the metadata that makes the sharing visible without
 * new infrastructure: a version counter, the last editor and last-modified
 * time, and the accumulating set of contributors who have written to it.
 * Presence, optimistic conflict detection and publish attribution all read
 * this one record rather than inferring state or standing up a separate
 * presence table. The metadata rides inside the same record as the draft
 * content, so clearing the draft — on publish, discard or revert — clears the
 * contributor set with it, and a fresh draft starts with just its author.
 *
 * Unlike the disposable scratch buffer, a draft is unpublished editor work on a
 * live site: if this store's backend ever changes, existing drafts must be
 * migrated, not dropped.
 */
final class SharedDraftStore {

  /**
   * The key prefix distinguishing draft keys from any other key space.
   */
  protected const KEY_PREFIX = 'neo_alchemist_draft';

  /**
   * The cache-tag prefix for the draft's preview.
   */
  protected const CACHE_TAG_PREFIX = 'neo_alchemist_draft';

  /**
   * The metadata a record carries when nothing has been written yet.
   *
   * Reads fall back to these, and a legacy record (a bare content array stored
   * before draft metadata existed) is read as content with these defaults — so
   * no draft content is lost to the shape change and 07 can re-seed metadata on
   * migration.
   */
  protected const EMPTY_METADATA = [
    'version' => 0,
    'last_editor' => NULL,
    'last_modified' => NULL,
    'contributors' => [],
  ];

  /**
   * Constructs the shared draft store.
   *
   * @param \Drupal\neo_alchemist\EditorState\EditorStateStoreInterface $store
   *   The backing store adapter (durable state in production).
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, recorded as last editor and added to the contributors.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for the last-modified stamp.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    protected readonly EditorStateStoreInterface $store,
    protected readonly AccountInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Reads the shared draft content for an entity/field.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array|null
   *   The draft content, or NULL when no draft is stored.
   */
  public function get(ComponentTreeItem $item): ?array {
    $record = $this->record($item);
    if ($record === NULL) {
      return NULL;
    }
    return is_array($record['value']) ? $record['value'] : NULL;
  }

  /**
   * Writes the shared draft for an entity/field, invalidating the preview.
   *
   * The write increments the version, records the current user as the last
   * editor with the current time, and adds them to the contributor set. These
   * are the mechanism behind presence and conflict detection — one record, no
   * separate presence table or heartbeat.
   *
   * When $expectedVersion is given, the write is optimistically guarded: if the
   * stored version is ahead of the one the session loaded, a colleague wrote
   * the draft in the meantime, so the write is refused rather than allowed to
   * overwrite their work with a stale copy. Passing NULL (the default) writes
   * unguarded — the per-user scratch buffer and non-session callers do not
   * carry a version.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array $value
   *   The draft content to store.
   * @param int|null $expectedVersion
   *   The draft version the session loaded, or NULL to write unguarded.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When $expectedVersion is behind the stored version.
   */
  public function set(ComponentTreeItem $item, array $value, ?int $expectedVersion = NULL): void {
    $record = $this->record($item);
    if ($conflict = $this->conflictFromRecord($expectedVersion, $record)) {
      throw $conflict;
    }
    $storedVersion = (int) ($record['version'] ?? 0);
    $uid = (int) $this->currentUser->id();

    $contributors = $record['contributors'] ?? [];
    if (!in_array($uid, $contributors, TRUE)) {
      $contributors[] = $uid;
    }

    $this->store->set($this->key($item), [
      'value' => $value,
      'version' => $storedVersion + 1,
      'last_editor' => $uid,
      'last_modified' => $this->time->getRequestTime(),
      'contributors' => $contributors,
    ]);
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item)]);
  }

  /**
   * Discards the shared draft for an entity/field, invalidating the preview.
   *
   * The whole record goes, so the contributor set and the rest of the metadata
   * are cleared with the content. Publish, discard and revert all reach here.
   *
   * The publish commit passes $expectedVersion so a stale publish is refused
   * rather than releasing a version the publisher never saw (someone edited the
   * draft while they sat on the confirmation). Discard and revert pass NULL:
   * throwing the draft away is deliberate, on whatever is there.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param int|null $expectedVersion
   *   The draft version the session loaded, or NULL to delete unguarded.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When $expectedVersion is behind the stored version.
   */
  public function delete(ComponentTreeItem $item, ?int $expectedVersion = NULL): void {
    if ($expectedVersion !== NULL && ($conflict = $this->conflictFromRecord($expectedVersion, $this->record($item)))) {
      throw $conflict;
    }
    $this->store->delete($this->key($item));
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item)]);
  }

  /**
   * The conflict a write at $expectedVersion would hit, or NULL if it is clear.
   *
   * The read-only counterpart of the guard set() and delete() enforce: the
   * editor and publish forms call this while validating so they can refuse a
   * stale save with a form-level error, in the phase where an error is legal,
   * rather than letting the write throw mid-submit. The comparison and the
   * last-editor attribution live in one place (conflictFromRecord()), so the
   * pre-check and the write-time throw can never disagree.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param int|null $expectedVersion
   *   The draft version the session loaded, or NULL for no check.
   *
   * @return \Drupal\neo_alchemist\EditorState\DraftConflictException|null
   *   The refusal a write would raise, or NULL when the write would be clear.
   */
  public function conflict(ComponentTreeItem $item, ?int $expectedVersion): ?DraftConflictException {
    return $this->conflictFromRecord($expectedVersion, $this->record($item));
  }

  /**
   * Builds the conflict for a carried version against an already-read record.
   *
   * The check is strictly-behind: a write at the current stored version is
   * clear (and goes on to increment it), so the same session re-saving its own
   * just-written draft is not a conflict against itself; only a version a
   * colleague has since moved past is refused. A NULL carried version is never
   * a conflict — the write is unguarded.
   *
   * @param int|null $expectedVersion
   *   The version the session carried, or NULL to skip the check.
   * @param array|null $record
   *   The raw record, already read by the caller, for last-editor attribution.
   *
   * @return \Drupal\neo_alchemist\EditorState\DraftConflictException|null
   *   The refusal, or NULL when the carried version is at or ahead of stored.
   */
  private function conflictFromRecord(?int $expectedVersion, ?array $record): ?DraftConflictException {
    $storedVersion = (int) ($record['version'] ?? 0);
    if ($expectedVersion === NULL || $expectedVersion >= $storedVersion) {
      return NULL;
    }
    $lastEditor = $record['last_editor'] ?? NULL;
    return new DraftConflictException($expectedVersion, $storedVersion, $lastEditor === NULL ? NULL : (int) $lastEditor);
  }

  /**
   * Whether a shared draft exists for an entity/field.
   *
   * A config-scope field item — the field default layout — never has a draft:
   * drafts are per-entity, unpublished editor work. That rule lives here, in
   * the store that owns draft existence, so a caller cannot get it wrong.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return bool
   *   TRUE when a draft is stored, FALSE otherwise.
   */
  public function has(ComponentTreeItem $item): bool {
    if ($item->belongsToFieldConfig()) {
      return FALSE;
    }
    return (bool) $this->get($item);
  }

  /**
   * The draft's version counter, incremented on every write.
   *
   * A session carries the version it loaded and a stale write is refused
   * (04); this is the value it compares against.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return int
   *   The current version, or 0 when no draft is stored.
   */
  public function version(ComponentTreeItem $item): int {
    return (int) ($this->record($item)['version'] ?? 0);
  }

  /**
   * The user id of the draft's last editor.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return int|null
   *   The last editor's user id, or NULL when no draft is stored.
   */
  public function lastEditor(ComponentTreeItem $item): ?int {
    $editor = $this->record($item)['last_editor'] ?? NULL;
    return $editor === NULL ? NULL : (int) $editor;
  }

  /**
   * The timestamp of the draft's last write.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return int|null
   *   The last-modified Unix timestamp, or NULL when no draft is stored.
   */
  public function lastModified(ComponentTreeItem $item): ?int {
    $modified = $this->record($item)['last_modified'] ?? NULL;
    return $modified === NULL ? NULL : (int) $modified;
  }

  /**
   * The set of user ids who have written to the draft.
   *
   * Accumulates across writes and is cleared with the record on publish,
   * discard and revert. Publish attribution names these before releasing the
   * draft as a whole.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return int[]
   *   The contributor user ids in the order they first wrote, empty when no
   *   draft is stored.
   */
  public function contributors(ComponentTreeItem $item): array {
    $contributors = $this->record($item)['contributors'] ?? [];
    return array_values(array_map('intval', $contributors));
  }

  /**
   * The cache tag whose invalidation makes the draft's preview re-render.
   *
   * A preview renders the stored layout until someone starts editing, at which
   * point it has to reflect the draft instead. Dynamic Page Cache serves a hit
   * without re-running the controller, so tagging the response with this tag —
   * and invalidating it inside every write and delete — is the only thing that
   * lets the cache find out an unsaved change is in play.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return string
   *   The cache tag.
   */
  public function cacheTag(ComponentTreeItem $item): string {
    return self::CACHE_TAG_PREFIX . ':' . $this->discriminator($item);
  }

  /**
   * Reads the raw draft record, or NULL when no draft is stored.
   *
   * A record wraps the draft content under 'value' alongside its metadata. A
   * value written before draft metadata existed is a bare content array with
   * no 'value' key; it is read as content with empty metadata, so a draft
   * saved just before this landed keeps rendering and 07 can re-seed its
   * metadata on migration. Missing metadata keys are backfilled from the empty
   * defaults, so every read sees a well-formed record shape.
   *
   * The 'value' key is what tells a modern record from a legacy one, and that
   * is sound because the content it wraps is a ['tree' => …, 'props' => …]
   * array that never carries a top-level 'value' key of its own — the load-
   * bearing invariant behind the detection below.
   */
  protected function record(ComponentTreeItem $item): ?array {
    $stored = $this->store->get($this->key($item));
    if (!is_array($stored)) {
      return NULL;
    }
    if (!array_key_exists('value', $stored)) {
      $stored = ['value' => $stored];
    }
    return $stored + self::EMPTY_METADATA;
  }

  /**
   * Builds the durable storage key for an entity/field's shared draft.
   */
  protected function key(ComponentTreeItem $item): string {
    return self::KEY_PREFIX . '.' . $this->discriminator($item);
  }

  /**
   * The entity/field discriminator behind both the key and the cache tag.
   *
   * Keyed by entity and field, with no user. The entity type id and the
   * langcode are folded in to fix two collisions the old key had: drafts
   * colliding across entity types that share an id, and translations of one
   * entity sharing a single draft. The revision is deliberately left out — a
   * draft is pre-publish, so it is revision-agnostic.
   */
  protected function discriminator(ComponentTreeItem $item): string {
    $entity = $item->getEntity();
    return implode('.', [
      $entity->getEntityTypeId(),
      $item->belongsToFieldConfig()
        ? $item->getFieldDefinition()->getTargetEntityTypeId()
        : (string) $entity->id(),
      $item->getFieldDefinition()->getName(),
      $entity->language()->getId(),
    ]);
  }

}
