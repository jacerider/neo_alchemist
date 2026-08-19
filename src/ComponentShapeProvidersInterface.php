<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Reads the value providers configured on the prop.
 *
 * The ComponentValue plugins that feed this prop — which are configured, which
 * are allowed, and the collection that runs them. A shape's value comes from
 * this chain, so this is the role that says where a value could come from,
 * where ComponentShapeValueInterface says what came out.
 *
 * @see \Drupal\neo_alchemist\ComponentValuePluginInterface
 * @see \Drupal\neo_alchemist\ComponentShapeValueInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeProvidersInterface extends ComponentShapeIdentityInterface {

  /**
   * Retrieves the list of plugin settings.
   *
   * @return array
   *   An array of plugins.
   */
  public function getPlugins(): array;

  /**
   * Checks if the shape has a plugin with the given ID.
   *
   * @param string $pluginId
   *   The ID of the plugin.
   *
   * @return bool
   *   TRUE if the plugin exists, FALSE otherwise.
   */
  public function hasPlugin(string $pluginId): bool;

  /**
   * Sets the plugin with the given ID and settings.
   *
   * This method unsets the current value collection and assigns the provided
   * settings to the plugin identified by the given plugin ID within the nested
   * plugin structure.
   *
   * @param string $pluginId
   *   The ID of the plugin to set.
   * @param array $settings
   *   (optional) An associative array of settings for the plugin. Defaults to
   *   an empty array.
   * @param bool $status
   *   (optional) Whether the plugin is enabled. Defaults to TRUE.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addPlugin(string $pluginId, array $settings = [], bool $status = TRUE): ComponentShapePluginInterface;

  /**
   * Gets the default plugins for the component shape.
   *
   * @return array
   *   An array of default plugins.
   */
  public function getDefaultPlugins(): array;

  /**
   * Determines if the current shape allows configurable plugins.
   *
   * This means the shape's parent is expanded.
   *
   * @return bool
   *   TRUE if the current shape is an expanded child, FALSE otherwise.
   */
  public function allowConfigurablePlugins(): bool;

  /**
   * Retrieves the collection of component shape plugin values.
   *
   * This method initializes the value collection if it has not been set yet.
   * It gathers the configurations for each plugin by iterating through the
   * filtered definitions from the shape and checking their status and settings.
   * The configurations are then used to create a new
   * ComponentShapePluginCollection instance, which is stored in the
   * valueCollection property.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginCollection
   *   The collection of component shape plugin values.
   */
  public function getValueCollection(): ComponentShapePluginCollection;

  /**
   * Determines if a value plugin is allowed for the shape.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return bool
   *   TRUE if the value plugin is allowed, FALSE otherwise.
   */
  public function allowValuePlugin(array $definition): bool;

}
