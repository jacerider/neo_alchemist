<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Drives the shape through initialisation and component events.
 *
 * `init()` is the pipeline that turns a configured shape into one holding a
 * value: schema default, provider chain, parent and override values, field
 * item. It is the line the rest of the shape is ordered around — the setup
 * setters run before it, and the value, form and field-item getters only mean
 * anything after.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeLifecycleInterface extends ComponentShapeIdentityInterface {

  /**
   * Initialize the shape and calculates the value of the field item.
   *
   * This method processes the field item value by starting with the schema
   * defaults, then modifying with value providers, and finally overlaying
   * the user input if applicable.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function init(): ComponentShapePluginInterface;

  /**
   * Allow or disallow initialization of specific plugins.
   *
   * If a shape has a default_plugins, this method can be used to prevent it
   * from being initialized.
   *
   * @param string $pluginId
   *   The ID of the plugin.
   * @param bool $allow
   *   Whether to allow initialization. Defaults to TRUE.
   *
   * @return $this
   */
  public function allowInitPlugins(string $pluginId, bool $allow = TRUE): ComponentShapePluginInterface;

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
