<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * The SDC preview workspace store: a developer's disposable prop overrides.
 *
 * The SDC preview workspace is a component-development tool used almost
 * entirely on a local environment: temporary prop values, style overrides and
 * neighbor context that drive one developer's preview iframe on a transient,
 * unsaved neo_component entity whose form never persists anything. This store
 * owns that state, taking the storage mechanics — the cache bin, the key format
 * and the expiry window — off the component entity's interface, so the entity
 * describes components rather than editor sessions.
 *
 * Sharing semantics are a property of the store, not of a backend: the current
 * user is folded into the key, so one developer's overrides on a shared
 * environment are their own — their Reset clears their own overrides and never
 * a colleague's. It stays disposable (cache-backed with a short expiry via
 * CacheEditorStateStore) exactly as before; the only change is that per-user
 * key. In tests the store is pointed at the in-memory adapter, so seeding a
 * prop value for a shape never writes through a global cache backend.
 *
 * Unlike the shared draft store, this store folds no cache-tag invalidation
 * into its writes: the preview iframe is reloaded by the AJAX response that
 * follows an edit, and the preview frame's own response is uncacheable
 * (max-age 0). What a value write does carry as a postcondition is dropping the
 * component's derived-settings memo (setValues()/resetValues()), so the same
 * request re-derives its prop shapes and filters against the new overrides —
 * the behaviour the workspace form and its memo-invalidation test depend on.
 */
final class SdcPreviewStore {

  /**
   * The key prefix distinguishing preview keys from any other key space.
   */
  protected const KEY_PREFIX = 'neo_alchemist_preview';

  /**
   * Constructs the SDC preview store.
   *
   * @param \Drupal\neo_alchemist\EditorState\EditorStateStoreInterface $store
   *   The backing store adapter (a short-TTL cache bin in production).
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, folded into the key so overrides are per-user.
   */
  public function __construct(
    protected readonly EditorStateStoreInterface $store,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Reads the prop-value overrides for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return array
   *   The overrides, structured like a placed instance's values
   *   (['props' => [...]]), or an empty array when nothing is overridden.
   */
  public function getValues(ComponentInterface $component): array {
    return $this->readArray($component, 'values');
  }

  /**
   * Whether any prop-value override is stored for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return bool
   *   TRUE when at least one override is stored, FALSE otherwise.
   */
  public function hasValues(ComponentInterface $component): bool {
    return !empty($this->getValues($component));
  }

  /**
   * Writes the prop-value overrides for a component.
   *
   * Dropping the component's derived-settings memo is a postcondition of the
   * write: getFilters() and getPropShapes() bake these overrides in, so the
   * memo must go for the same request to re-derive against the new values.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   * @param array $values
   *   The overrides to store.
   */
  public function setValues(ComponentInterface $component, array $values): void {
    $component->invalidateDerivedSettings();
    $this->store->set($this->key($component, 'values'), $values);
  }

  /**
   * Clears the prop-value overrides for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   */
  public function resetValues(ComponentInterface $component): void {
    $component->invalidateDerivedSettings();
    $this->store->delete($this->key($component, 'values'));
  }

  /**
   * Reads all style overrides for a component, keyed by shape id.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return array
   *   The style overrides, or an empty array when none are stored.
   */
  public function getStyles(ComponentInterface $component): array {
    return $this->readArray($component, 'styles');
  }

  /**
   * Whether any style override is stored for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return bool
   *   TRUE when at least one style override is stored, FALSE otherwise.
   */
  public function hasStyles(ComponentInterface $component): bool {
    return !empty($this->getStyles($component));
  }

  /**
   * Reads the style override for a single shape.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   * @param string $shapeId
   *   The shape id.
   *
   * @return string|null
   *   The overriding style value, or NULL when the shape has none.
   */
  public function getStyle(ComponentInterface $component, string $shapeId): ?string {
    return $this->getStyles($component)[$shapeId] ?? NULL;
  }

  /**
   * Writes the style override for a single shape.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   * @param string $shapeId
   *   The shape id.
   * @param string $shapeValue
   *   The overriding style value.
   */
  public function setStyle(ComponentInterface $component, string $shapeId, string $shapeValue): void {
    $styles = $this->getStyles($component);
    $styles[$shapeId] = $shapeValue;
    $this->store->set($this->key($component, 'styles'), $styles);
  }

  /**
   * Clears every style override for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   */
  public function resetStyles(ComponentInterface $component): void {
    $this->store->delete($this->key($component, 'styles'));
  }

  /**
   * Reads the neighbor-component context for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return array
   *   ['above' => string|null, 'below' => string|null] of SDC ids, or [].
   */
  public function getContext(ComponentInterface $component): array {
    return $this->readArray($component, 'context');
  }

  /**
   * Writes the neighbor-component context for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   * @param string|null $above
   *   The SDC id to render above the component, or NULL.
   * @param string|null $below
   *   The SDC id to render below the component, or NULL.
   */
  public function setContext(ComponentInterface $component, ?string $above, ?string $below): void {
    $this->store->set($this->key($component, 'context'), [
      'above' => $above ?: NULL,
      'below' => $below ?: NULL,
    ]);
  }

  /**
   * Clears the neighbor-component context for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   */
  public function resetContext(ComponentInterface $component): void {
    $this->store->delete($this->key($component, 'context'));
  }

  /**
   * Reads one slice of a component's preview state as an array.
   *
   * Every read of a stored slice (values, styles, context) coerces a missing
   * or non-array entry to an empty array, so the shared shape lives here rather
   * than in each getter.
   */
  protected function readArray(ComponentInterface $component, string $type): array {
    $value = $this->store->get($this->key($component, $type));
    return is_array($value) ? $value : [];
  }

  /**
   * Builds the per-user storage key for a slice of a component's preview state.
   *
   * The component id identifies the workspace; the current user id makes the
   * overrides private to their author, so a colleague on a shared development
   * environment neither sees them nor clears them with their own Reset.
   */
  protected function key(ComponentInterface $component, string $type): string {
    return implode('.', [
      self::KEY_PREFIX,
      $component->id(),
      $type,
      (int) $this->currentUser->id(),
    ]);
  }

}
