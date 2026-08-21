<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * The disposable editor-state adapter, backed by a cache bin with a short TTL.
 *
 * The SDC preview workspace's overrides — the temporary prop values, styles and
 * neighbor context that drive a developer's preview iframe on a transient,
 * never-saved entity — are disposable scratch by design. A cache bin with a
 * short expiry is the correct home for that: losing it on its own is correct
 * behaviour, not data loss, so unlike the shared draft it is never migrated.
 *
 * It is the fourth seam adapter, alongside the per-user private-tempstore, the
 * durable state, and the in-memory test adapter. The expiry is a property of
 * this adapter, not of the seam (which knows only read/write/delete of a keyed
 * value); the store built on it (SdcPreviewStore) owns the per-user key
 * derivation. In tests the SdcPreviewStore is pointed at the in-memory adapter
 * instead, so seeding a prop value never touches a cache backend.
 */
final class CacheEditorStateStore implements EditorStateStoreInterface {

  /**
   * Constructs the adapter.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache bin holding the disposable preview state.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for the absolute expiry timestamp.
   * @param int $expire
   *   The lifetime of a written value, in seconds.
   */
  public function __construct(
    protected readonly CacheBackendInterface $cache,
    protected readonly TimeInterface $time,
    protected readonly int $expire,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    $cached = $this->cache->get($key);
    return $cached ? $cached->data : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value): void {
    $this->cache->set($key, $value, $this->time->getRequestTime() + $this->expire);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    $this->cache->delete($key);
  }

}
