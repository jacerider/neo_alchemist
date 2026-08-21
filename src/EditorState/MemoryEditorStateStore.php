<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

/**
 * The in-memory editor-state adapter, for tests.
 *
 * A dumb key/value array with no user awareness and no persistence: two
 * sessions writing the same draft, and asserting what the other observes, is a
 * test that cannot be written against the production backends without leaking
 * state between test methods. Segmentation (per-user or shared) is a property
 * of the *store* that derives the keys, not of this adapter — which is exactly
 * why one adapter can back both a per-user scratch store and, later, a shared
 * draft store without change.
 */
final class MemoryEditorStateStore implements EditorStateStoreInterface {

  /**
   * The stored values, keyed by key.
   *
   * @var array<string, mixed>
   */
  protected array $values = [];

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    return $this->values[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value): void {
    $this->values[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    unset($this->values[$key]);
  }

}
