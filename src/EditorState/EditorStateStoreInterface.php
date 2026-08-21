<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

/**
 * A keyed store for a piece of editor session state.
 *
 * This is the seam the collaboration model rides on. It describes only read,
 * write and delete of a value by key — nothing about who can see it, how long
 * it lives, or which backend holds it. Those are properties of the *store*
 * built on top of a given adapter (see EditorScratchStore), not of the seam:
 * so the sharing semantics of a piece of editor state become a property of the
 * store you reached for rather than of the arguments you passed.
 *
 * Two adapters implement it: a production one backed by a real Drupal backend
 * (PrivateTempStoreEditorStateStore) and an in-memory one for tests
 * (MemoryEditorStateStore). Because the adapter is the injection point, a
 * store's behaviour is testable by substitution rather than by side effect.
 */
interface EditorStateStoreInterface {

  /**
   * Reads the value stored under a key.
   *
   * @param string $key
   *   The key.
   *
   * @return mixed
   *   The stored value, or NULL when nothing is stored under the key.
   */
  public function get(string $key): mixed;

  /**
   * Writes a value under a key.
   *
   * @param string $key
   *   The key.
   * @param mixed $value
   *   The value to store.
   */
  public function set(string $key, mixed $value): void;

  /**
   * Deletes whatever is stored under a key.
   *
   * @param string $key
   *   The key.
   */
  public function delete(string $key): void;

}
