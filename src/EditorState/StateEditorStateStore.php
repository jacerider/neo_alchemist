<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Core\State\StateInterface;

/**
 * The durable editor-state adapter, backed by state.
 *
 * State is the site's durable key/value store: what is written here survives
 * the request and every user, which is exactly what the collaborative layout
 * draft needs — keyed by entity and field, with no user segment, so two editors
 * building the same page work on one shared artifact rather than two forks. It
 * is the correct home for the shared draft store, the counterpart to the
 * per-user private-tempstore adapter behind the live form buffer.
 */
final class StateEditorStateStore implements EditorStateStoreInterface {

  /**
   * Constructs the adapter.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    return $this->state->get($key);
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value): void {
    $this->state->set($key, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    $this->state->delete($key);
  }

}
