<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeChildrenPluginInterface extends ComponentShapeChildrenMatchPluginInterface {

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
