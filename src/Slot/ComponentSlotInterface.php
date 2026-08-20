<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Slot;

use Drupal\Core\Render\RenderableInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentSlotInterface extends RenderableInterface {

  /**
   * Gets the component.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component instance.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Gets the slot name.
   *
   * @return string
   *   The slot name.
   */
  public function getName(): string;

  /**
   * Gets the slot schema.
   *
   * @return array
   *   The slot schema.
   */
  public function getSchema(): array;

  /**
   * Gets the slot title.
   *
   * @return array
   *   The slot title.
   */
  public function getTitle(): string;

  /**
   * Gets the slot description.
   *
   * @return string
   *   The slot description.
   */
  public function getDescription(): string;

  /**
   * Gets the slot settings.
   *
   * @return array
   *   The slot settings.
   */
  public function getSettings(): array;

  /**
   * Gets the slot plugins.
   *
   * @return \Drupal\neo_alchemist\Slot\ComponentSlotPluginInterface[]
   *   The slot plugins.
   */
  public function getPlugins(): array;

  /**
   * Gets a slot plugin by UUID.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   *
   * @return \Drupal\neo_alchemist\Slot\ComponentSlotPluginInterface|null
   *   The slot plugin or NULL if not found.
   */
  public function getPlugin(string $uuid): ?ComponentSlotPluginInterface;

  /**
   * Gets the resolved Twig key of every slot plugin.
   *
   * These are the keys a slot template addresses its items by, and the keys the
   * render array built by ::toRenderable() uses. A plugin with no explicitly
   * configured key falls back to its plugin id, suffixed `_2`, `_3`, … when
   * that would collide with a sibling or with a reserved context variable.
   *
   * @return string[]
   *   Resolved Twig keys, keyed by plugin UUID.
   */
  public function getKeys(): array;

  /**
   * Gets the explicitly configured Twig key of a slot plugin.
   *
   * Use ::getKeys() for the key actually rendered — this returns only what a
   * site builder typed, so it is NULL for a plugin using its derived key.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   *
   * @return string|null
   *   The configured key, or NULL when none is set.
   */
  public function getKey(string $uuid): ?string;

  /**
   * Sets the explicitly configured Twig key of a slot plugin.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   * @param string|null $key
   *   The key, or NULL/'' to clear it and fall back to the derived key.
   *
   * @return $this
   */
  public function setKey(string $uuid, ?string $key): self;

  /**
   * Adds a slot plugin.
   *
   * @param string $plugin_id
   *   The slot plugin id.
   * @param array $settings
   *   The plugin settings.
   *
   * @return \Drupal\neo_alchemist\Slot\ComponentSlotPluginInterface
   *   The slot plugin.
   */
  public function addPlugin(string $plugin_id, $settings = []): ComponentSlotPluginInterface;

  /**
   * Removes a slot plugin.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   *
   * @return $this
   */
  public function removePlugin(string $uuid): self;

  /**
   * Converts the slot to an array.
   *
   * @return array
   *   The slot as an array.
   */
  public function toArray(): array;

}
