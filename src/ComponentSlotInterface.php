<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Render\RenderableInterface;

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
   * @return \Drupal\neo_alchemist\ComponentSlotPluginInterface[]
   *   The slot plugins.
   */
  public function getPlugins(): array;

  /**
   * Gets a slot plugin by UUID.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   *
   * @return \Drupal\neo_alchemist\ComponentSlotPluginInterface|null
   *   The slot plugin or NULL if not found.
   */
  public function getPlugin(string $uuid): ?ComponentSlotPluginInterface;

  /**
   * Adds a slot plugin.
   *
   * @param string $plugin_id
   *   The slot plugin id.
   * @param array $settings
   *   The plugin settings.
   *
   * @return \Drupal\neo_alchemist\ComponentSlotPluginInterface
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
