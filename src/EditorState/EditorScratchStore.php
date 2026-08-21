<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * The per-user scratch store: the live form buffer.
 *
 * The per-instance prop values written while an editor's component form is
 * open, and read to drive that editor's preview iframe, are not a draft in the
 * collaborative sense — they are the unsaved contents of one person's open
 * form. This store declares that: it is per-user, it has its own key prefix so
 * it no longer borrows the whole-tree draft's key derivation, and it folds
 * cache-tag invalidation into every write so a caller cannot ship a stale
 * preview by forgetting a line.
 *
 * Per-user-ness is a property of the store, not of a backend: the current user
 * id is folded into the key, so the buffer stays private to its writer over any
 * adapter — production (private tempstore, itself per-user) or the in-memory
 * adapter used in tests. Nothing about which user can see the buffer changes;
 * the store simply says so.
 *
 * The buffer is disposable by design (an unsaved form's contents), so it is
 * never migrated: unlike the shared draft, losing it on a key-space change is
 * correct behaviour, not data loss.
 */
final class EditorScratchStore {

  /**
   * The key prefix distinguishing scratch keys from any other key space.
   */
  protected const KEY_PREFIX = 'neo_alchemist_scratch';

  /**
   * The cache-tag prefix for the buffer's preview.
   */
  protected const CACHE_TAG_PREFIX = 'neo_alchemist_scratch';

  /**
   * Constructs the scratch store.
   *
   * @param \Drupal\neo_alchemist\EditorState\EditorStateStoreInterface $store
   *   The backing store adapter.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, folded into the key so the buffer is per-user.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    protected readonly EditorStateStoreInterface $store,
    protected readonly AccountInterface $currentUser,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Reads the buffered values for a component instance.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the instance belongs to.
   * @param string $uuid
   *   The component instance uuid.
   *
   * @return array|null
   *   The buffered values, or NULL when nothing is buffered for this user.
   */
  public function get(ComponentTreeItem $item, string $uuid): ?array {
    $value = $this->store->get($this->key($item, $uuid));
    return is_array($value) ? $value : NULL;
  }

  /**
   * Buffers values for a component instance, invalidating the preview.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the instance belongs to.
   * @param string $uuid
   *   The component instance uuid.
   * @param array $values
   *   The values to buffer.
   */
  public function set(ComponentTreeItem $item, string $uuid, array $values): void {
    $this->store->set($this->key($item, $uuid), $values);
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item, $uuid)]);
  }

  /**
   * Discards the buffer for a component instance, invalidating the preview.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the instance belongs to.
   * @param string $uuid
   *   The component instance uuid.
   */
  public function delete(ComponentTreeItem $item, string $uuid): void {
    $this->store->delete($this->key($item, $uuid));
    $this->cacheTagsInvalidator->invalidateTags([$this->cacheTag($item, $uuid)]);
  }

  /**
   * The cache tag whose invalidation makes the buffer's preview re-render.
   *
   * The preview controller tags its response with this so that Dynamic Page
   * Cache — which serves a hit without re-running the controller — finds out
   * when the buffer is written or cleared.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item the instance belongs to.
   * @param string $uuid
   *   The component instance uuid.
   *
   * @return string
   *   The cache tag.
   */
  public function cacheTag(ComponentTreeItem $item, string $uuid): string {
    return self::CACHE_TAG_PREFIX . ':' . $this->scope($item) . '.' . $item->getFieldDefinition()->getName() . '.' . $uuid;
  }

  /**
   * Builds the per-user storage key for a component instance's buffer.
   */
  protected function key(ComponentTreeItem $item, string $uuid): string {
    return implode('.', [
      self::KEY_PREFIX,
      $this->scope($item),
      $item->getFieldDefinition()->getName(),
      $uuid,
      (int) $this->currentUser->id(),
    ]);
  }

  /**
   * The entity/field scope discriminator for the scratch key.
   *
   * The shared draft's discriminator (SharedDraftStore) additionally folds in
   * the entity type and langcode, to avoid drafts colliding across entity types
   * and translations sharing one draft. Scratch does not need to: its key also
   * carries the globally-unique component instance uuid, which already
   * disambiguates, so those collisions are unreachable here.
   */
  protected function scope(ComponentTreeItem $item): string {
    return $item->belongsToFieldConfig()
      ? $item->getFieldDefinition()->getTargetEntityTypeId()
      : (string) $item->getEntity()->id();
  }

}
