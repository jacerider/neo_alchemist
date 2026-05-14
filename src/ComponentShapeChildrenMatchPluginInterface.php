<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Minimum contract consumed by ComponentValueChildrenMatchTrait.
 *
 * Shapes that satisfy this interface can be used as the root of a
 * field-to-child mapping configuration (see entity_load, site_settings, etc.
 * ComponentValue providers). The wider ComponentShapeChildrenPluginInterface
 * extends this and adds introspection/lock methods that the full
 * ChildrenShapeBase flow requires.
 */
interface ComponentShapeChildrenMatchPluginInterface extends ComponentShapePluginInterface {

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
   * Get the names of the child shapes.
   *
   * @return string[]
   *   The names of the child shapes.
   */
  public function getChildShapeNames(): array;

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

}
