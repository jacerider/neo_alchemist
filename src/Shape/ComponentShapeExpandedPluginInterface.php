<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeExpandedPluginInterface {

  /**
   * Allow the child shape to be expanded.
   *
   * @return bool
   *   Returns TRUE if the child shape is allowed to be expanded, FALSE
   *   otherwise.
   */
  public function allowExpanded(): bool;

}
