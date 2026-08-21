<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
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
   * Constructs the shared draft store.
   *
   * @param \Drupal\neo_alchemist\EditorState\EditorStateStoreInterface $store
   *   The backing store adapter (durable state in production).
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    protected readonly EditorStateStoreInterface $store,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Reads the shared draft for an entity/field.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   *
   * @return array|null
   *   The draft value, or NULL when no draft is stored.
   */
  public function get(ComponentTreeItem $item): ?array {
    $value = $this->store->get($this->key($item));
    return is_array($value) ? $value : NULL;
  }

  /**
   * Writes the shared draft for an entity/field, invalidating the preview.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   * @param array $value
   *   The draft value to store.
   */
  public function set(ComponentTreeItem $item, array $value): void {
    $this->store->set($this->key($item), $value);
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item)]);
  }

  /**
   * Discards the shared draft for an entity/field, invalidating the preview.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the draft belongs to.
   */
  public function delete(ComponentTreeItem $item): void {
    $this->store->delete($this->key($item));
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item)]);
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
