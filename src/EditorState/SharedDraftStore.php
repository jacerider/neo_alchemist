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
 * Editor side panels (AlchemistPanelInterface) draft into the same record, one
 * key each under 'panels', so their data is versioned, reverted, reset and
 * published with the layout rather than beside it. The layout keeps a version
 * of its own as well, which only a tree write moves: what a component save
 * guards on, so a panel's write never refuses one (see set()).
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
   * What a record carries besides its layout value, before anything is written.
   *
   * Reads fall back to these, and a legacy record (a bare content array stored
   * before draft metadata existed) is read as content with these defaults — so
   * no draft content is lost to the shape change and 07 can re-seed metadata on
   * migration. A record written before editor panels existed has no 'panels'
   * key and reads as having none.
   */
  protected const EMPTY_METADATA = [
    'version' => 0,
    'last_editor' => NULL,
    'last_modified' => NULL,
    'contributors' => [],
    'panels' => [],
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
   *   The draft content, or NULL when no layout draft is stored — including
   *   when the draft holds only editor panel data (see setPanel()).
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
   * stored layout version is ahead of the one the session loaded, a colleague
   * wrote the layout in the meantime, so the write is refused rather than
   * allowed to overwrite their work with a stale copy. Passing NULL (the
   * default) writes unguarded — the per-user scratch buffer and non-session
   * callers do not carry a version.
   *
   * The guard is the layout version (layoutVersion()), not the draft's: a
   * panel's write never touches the layout this replaces, so it is no reason
   * to refuse it. The SEO analysis writes its scores after every layout write,
   * and against the draft's version that made every form opened just before
   * it stale — the editor's own next save was refused as a colleague's.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array $value
   *   The draft content to store.
   * @param int|null $expectedVersion
   *   The layout version the session loaded, or NULL to write unguarded.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When $expectedVersion is behind the stored layout version.
   */
  public function set(ComponentTreeItem $item, array $value, ?int $expectedVersion = NULL): void {
    $record = $this->record($item);
    if ($conflict = $this->layoutConflictFromRecord($expectedVersion, $record)) {
      throw $conflict;
    }
    // The editor panels' data rides along untouched: a tree write replaces the
    // layout, never what a panel drafted beside it.
    $this->writeEdit($item, $record, $value, $record['panels'] ?? [], TRUE);
  }

  /**
   * Reads every editor panel's drafted data for an entity/field.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array
   *   Panel id => drafted data, empty when no draft or no panel has written.
   *
   * @see \Drupal\neo_alchemist\Panel\AlchemistPanelInterface
   */
  public function panels(ComponentTreeItem $item): array {
    $panels = $this->record($item)['panels'] ?? [];
    return is_array($panels) ? $panels : [];
  }

  /**
   * Reads one editor panel's drafted data, or NULL when it has none.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param string $panelId
   *   The panel plugin id.
   *
   * @return mixed
   *   The drafted data, or NULL.
   */
  public function getPanel(ComponentTreeItem $item, string $panelId): mixed {
    return $this->panels($item)[$panelId] ?? NULL;
  }

  /**
   * Writes one editor panel's drafted data, under the same version guard.
   *
   * A panel write is a draft write like any other: it increments the version,
   * records the editor, and is refused when $expectedVersion is behind. The
   * layout value is left as it is. With no draft yet, the record is created
   * with no layout value — the layout has not changed, so publishing it keeps
   * the entity's own layout rather than a snapshot taken now — and has() turns
   * TRUE so Save and Revert appear. NULL data removes the panel's key.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param string $panelId
   *   The panel plugin id.
   * @param mixed $data
   *   The panel's data; anything serializable. NULL removes it.
   * @param int|null $expectedVersion
   *   The draft version the session loaded, or NULL to write unguarded.
   *
   * @throws \Drupal\neo_alchemist\EditorState\DraftConflictException
   *   When $expectedVersion is behind the stored version.
   */
  public function setPanel(ComponentTreeItem $item, string $panelId, mixed $data, ?int $expectedVersion = NULL): void {
    $record = $this->record($item);
    if ($conflict = $this->conflictFromRecord($expectedVersion, $record)) {
      throw $conflict;
    }
    $panels = $record['panels'] ?? [];
    if ($data === NULL) {
      unset($panels[$panelId]);
    }
    else {
      $panels[$panelId] = $data;
    }
    $this->writeEdit($item, $record, $record['value'] ?? NULL, $panels, FALSE);
  }

  /**
   * Writes an edit: the new content, with the version and attribution moved on.
   *
   * The write increments the version, records the current user as the last
   * editor with the current time, and adds them to the contributor set — for a
   * tree write and a panel write alike. Only a tree write moves the layout
   * version and its editor; a panel write carries them over as they were.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array|null $record
   *   The record as read before the write, or NULL when there was none.
   * @param array|null $value
   *   The layout value to store, or NULL when the layout has no draft.
   * @param array $panels
   *   The panel data to store.
   * @param bool $layout
   *   Whether this writes the layout (set()) rather than a panel's data.
   */
  private function writeEdit(ComponentTreeItem $item, ?array $record, ?array $value, array $panels, bool $layout): void {
    $uid = (int) $this->currentUser->id();
    $contributors = $record['contributors'] ?? [];
    if (!in_array($uid, $contributors, TRUE)) {
      $contributors[] = $uid;
    }
    $layoutVersion = $this->layoutVersionOf($record);
    $this->writeRecord($item, [
      'value' => $value,
      'version' => (int) ($record['version'] ?? 0) + 1,
      'layout_version' => $layout ? $layoutVersion + 1 : $layoutVersion,
      'last_editor' => $uid,
      'layout_editor' => $layout ? $uid : $this->layoutEditorOf($record),
      'last_modified' => $this->time->getRequestTime(),
      'contributors' => $contributors,
      'panels' => $panels,
    ]);
  }

  /**
   * Seeds a re-keyed in-flight draft as a well-formed record at this key.
   *
   * The re-key migration (neo_alchemist_update_11008) walks the old draft key
   * space and moves each existing whole-tree draft here. A pre-migration draft
   * is a bare content array with none of the metadata 03 introduced, so this
   * wraps it as a record and seeds the version and last-modified stamp it
   * lacks; the last editor is left unknown (NULL) and the contributor set
   * empty, because nothing recorded who wrote a draft before it existed.
   * Version starts at 1: the content is one draft that was written and
   * persisted, so the next save increments it to 2 and optimistic conflict
   * detection behaves from there.
   *
   * Unlike set(), a migration is not an edit: it does not increment a version,
   * attribute the current user (uid 0 during an update) or accumulate a
   * contributor. It keeps set()'s one postcondition — the write invalidates the
   * preview cache tag — so a migrated draft's preview notices it at once.
   *
   * The key is derived here rather than in the update hook, so the migration
   * re-keys THROUGH the store: it never reconstructs the key the spec made
   * private, and folding the entity type and langcode in — the collision fix —
   * happens in one place (discriminator()) for both the live path and the
   * migration.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the migrated draft belongs to.
   * @param array $value
   *   The pre-migration draft content, moved across unchanged.
   */
  public function seedMigratedRecord(ComponentTreeItem $item, array $value): void {
    $this->writeRecord($item, [
      'value' => $value,
      'version' => 1,
      'last_editor' => NULL,
      'last_modified' => $this->time->getRequestTime(),
      'contributors' => [],
    ]);
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
    $this->invalidate($item);
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
   * The conflict a layout write at $expectedVersion would hit, or NULL.
   *
   * The pre-check for set(), as conflict() is for publish: the component form
   * calls it while validating, with the layout version it loaded.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param int|null $expectedVersion
   *   The layout version the session loaded, or NULL for no check.
   *
   * @return \Drupal\neo_alchemist\EditorState\DraftConflictException|null
   *   The refusal a layout write would raise, or NULL when it would be clear.
   */
  public function layoutConflict(ComponentTreeItem $item, ?int $expectedVersion): ?DraftConflictException {
    return $this->layoutConflictFromRecord($expectedVersion, $this->record($item));
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
    $lastEditor = $record['last_editor'] ?? NULL;
    return $this->conflictAt($expectedVersion, (int) ($record['version'] ?? 0), $lastEditor === NULL ? NULL : (int) $lastEditor);
  }

  /**
   * As conflictFromRecord(), against the layout version and its editor.
   */
  private function layoutConflictFromRecord(?int $expectedVersion, ?array $record): ?DraftConflictException {
    return $this->conflictAt($expectedVersion, $this->layoutVersionOf($record), $this->layoutEditorOf($record));
  }

  /**
   * The strictly-behind comparison both guards share.
   */
  private function conflictAt(?int $expectedVersion, int $storedVersion, ?int $lastEditor): ?DraftConflictException {
    if ($expectedVersion === NULL || $expectedVersion >= $storedVersion) {
      return NULL;
    }
    return new DraftConflictException($expectedVersion, $storedVersion, $lastEditor);
  }

  /**
   * A record's layout version: its own, else its draft version.
   *
   * A record written before the layout had a version of its own falls back to
   * the draft's, which is what a form opened then round-tripped — so the
   * change refuses no save that was clear before it.
   */
  private function layoutVersionOf(?array $record): int {
    return (int) ($record['layout_version'] ?? $record['version'] ?? 0);
  }

  /**
   * A record's last layout editor, falling back as layoutVersionOf() does.
   */
  private function layoutEditorOf(?array $record): ?int {
    $editor = array_key_exists('layout_editor', $record ?? []) ? $record['layout_editor'] : ($record['last_editor'] ?? NULL);
    return $editor === NULL ? NULL : (int) $editor;
  }

  /**
   * Whether a shared draft exists for an entity/field.
   *
   * A config-scope field item — the field default layout — never has a draft:
   * drafts are per-entity, unpublished editor work. That rule lives here, in
   * the store that owns draft existence, so a caller cannot get it wrong. A
   * record holding only editor panel data is a draft too: it has something to
   * publish or revert even though the layout is unchanged.
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
    return (bool) $this->get($item) || $this->panels($item) !== [];
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
   * The layout's version counter, incremented by set() and nothing else.
   *
   * What the component form carries and set() guards on; version() moves with
   * panel writes too.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return int
   *   The current layout version, or 0 when no draft is stored.
   */
  public function layoutVersion(ComponentTreeItem $item): int {
    return $this->layoutVersionOf($this->record($item));
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
   * Writes a whole record at this store's key and invalidates the preview.
   *
   * The two write paths — the versioning edit (writeEdit(), behind set() and
   * setPanel()) and the migration seed — differ only in the metadata they
   * compute; the write itself and its invalidation
   * postcondition are the same, and live here so neither path can add a key to
   * the record and forget to write it, nor write without invalidating.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array $record
   *   The full record: the draft content under 'value' plus its metadata.
   */
  private function writeRecord(ComponentTreeItem $item, array $record): void {
    $this->store->set($this->key($item), $record);
    $this->invalidate($item);
  }

  /**
   * Invalidates the draft preview's cache tag.
   *
   * The single point every write and delete routes its invalidation through, so
   * the guarantee that a draft change always makes the preview notice is
   * structural — there is one line to get right, not one per mutation.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   */
  private function invalidate(ComponentTreeItem $item): void {
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item)]);
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
