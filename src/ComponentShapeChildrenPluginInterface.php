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
  public function getChildShapes(int $delta = 0): array;

}
