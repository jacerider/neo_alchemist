<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Says whether the shape initialised, and receives the component's events.
 *
 * `init()` is the line the rest of the shape is ordered around — the setup
 * setters run before it, and the value, form and field-item getters only mean
 * anything after. Driving it is not part of this role: init() and the setters
 * that feed it are on ComponentShapeSetupInterface, which the union does not
 * extend, so an initialised shape cannot be set up or initialised again.
 *
 * What is left is what an initialised shape still answers to — whether it got
 * there, and the events its component fires as it is saved and edited.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeSetupInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeLifecycleInterface extends ComponentShapeIdentityInterface {

  /**
   * Checks if the shape is initialized.
   *
   * @return bool
   *   TRUE if the shape is initialized, FALSE otherwise.
   */
  public function isInitialized(): bool;

  /**
   * Called when the shape is added to a component.
   */
  public function onAdd(): void;

  /**
   * Called when the shape is removed from a component.
   */
  public function onRemove(): void;

  /**
   * Called when the shape is updated.
   */
  public function onUpdate(): void;

  /**
   * Handles the event when a plugin is added to a component prop.
   *
   * @param string $pluginId
   *   The ID of the plugin being added.
   */
  public function onPluginAdd($pluginId): void;

  /**
   * Handles the event when a plugin is removed from a component prop.
   *
   * @param string $pluginId
   *   The ID of the plugin being removed.
   */
  public function onPluginRemove($pluginId): void;

}
