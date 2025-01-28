<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeChildrenPluginInterface {

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
   * Get the names of the child shapes.
   *
   * @return string[]
   *   The names of the child shapes.
   */
  public function getChildShapeNames(): array;

  /**
   * Check if the schema is a single property.
   *
   * @return bool
   *   Whether the schema is a single property.
   */
  public function isSingleProp(): bool;

}
