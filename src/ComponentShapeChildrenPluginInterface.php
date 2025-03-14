<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeChildrenPluginInterface extends ComponentShapePluginInterface {

  /**
   * Get child shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The child shapes.
   */
  public function getChildShapes(): array;

  /**
   * Hide a child shape.
   *
   * @param string $shapeName
   *   The name of the child shape.
   * @param bool $hide
   *   Whether to hide the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function hideChildShape(string $shapeName, $hide = TRUE): self;

  /**
   * Default a child shape.
   *
   * @param string $shapeName
   *   The name of the child shape.
   * @param bool $default
   *   Whether to default the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function defaultChildShape(string $shapeName, $default = TRUE): self;

  /**
   * Set child shape plugins.
   *
   * @param string $shapeName
   *   The name of the child shape.
   * @param array $plugins
   *   The plugins to set. The array should be keyed by the plugin ID and the
   *   values should be the plugin configuration.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function setChildShapePlugins(string $shapeName, array $plugins): self;

  /**
   * Get the names of the child shapes.
   *
   * @return string[]
   *   The names of the child shapes.
   */
  public function getChildShapeNames(): array;

  /**
   * Get the refs of the child shapes.
   *
   * @return string[]
   *   The refs of the child shapes.
   */
  public function getChildShapeRefs(): array;

  /**
   * Check if the schema is a single property.
   *
   * @return bool
   *   Whether the schema is a single property.
   */
  public function isSingleProp(): bool;

}
