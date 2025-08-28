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
   * @param int|null $delta
   *   The delta of the child shape to retrieve.
   * @param mixed $value
   *   (optional) The value to set on the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The child shapes.
   */
  public function getChildShapes(int|null $delta = NULL, mixed $value = NULL): array;

  /**
   * Hide a child shape.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   * @param bool $hide
   *   Whether to hide the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function hideChildShape(string $shapeId, $hide = TRUE): self;

  /**
   * Check if a child shape is hidden.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   *
   * @return bool|null
   *   Whether the child shape is hidden.
   */
  public function isHiddenChildShape(string $shapeId): ?bool;

  /**
   * Default a child shape.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   * @param bool $default
   *   Whether to default the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function defaultChildShape(string $shapeId, $default = TRUE): self;

  /**
   * Check if a child shape is default.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   *
   * @return bool|null
   *   Whether the child shape is default.
   */
  public function isDefaultChildShape(string $shapeId): ?bool;

  /**
   * Lock a child shape.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   * @param bool $lock
   *   Whether to lock the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function lockChildShape(string $shapeId, $lock = TRUE): self;

  /**
   * Check if a child shape is locked.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   *
   * @return bool|null
   *   Whether the child shape is locked.
   */
  public function isLockedChildShape(string $shapeId): ?bool;

  /**
   * Enable a child shape plugin.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   * @param string $pluginId
   *   The ID of the plugin.
   * @param array $settings
   *   The settings for the plugin.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function enableChildShapePlugin(string $shapeId, string $pluginId, array $settings = []): self;

  /**
   * Disable a child shape plugin.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   * @param string $pluginId
   *   The ID of the plugin.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The child shape.
   */
  public function disableChildShapePlugin(string $shapeId, string $pluginId): self;

  /**
   * Get child shape plugins.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   *
   * @return array
   *   The child shape plugins.
   */
  public function getChildShapePlugins(string $shapeId): array;

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
