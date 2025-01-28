<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeInterablePluginInterface {

  /**
   * Get the max number of items.
   *
   * @return int
   *   The max number of items.
   */
  public function getMaxItems(): int;

  /**
   * Get the min number of items.
   *
   * @return int
   *   The min number of items.
   */
  public function getMinItems(): int;

}
