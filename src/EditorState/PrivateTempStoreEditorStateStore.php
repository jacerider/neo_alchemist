<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * The production editor-state adapter, backed by private tempstore.
 *
 * Private tempstore is per-user by construction — every read and write is
 * scoped to the current user (or, for the anonymous, their session) by the
 * backend itself. That makes it the correct home for the live form buffer: one
 * editor's unsaved work, driving one editor's preview iframe.
 */
final class PrivateTempStoreEditorStateStore implements EditorStateStoreInterface {

  /**
   * The private tempstore.
   */
  protected PrivateTempStore $store;

  /**
   * Constructs the adapter.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param string $collection
   *   The tempstore collection to wrap, supplied by the service wiring.
   */
  public function __construct(PrivateTempStoreFactory $tempStoreFactory, string $collection) {
    $this->store = $tempStoreFactory->get($collection);
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    return $this->store->get($key);
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value): void {
    $this->store->set($key, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    $this->store->delete($key);
  }

}
