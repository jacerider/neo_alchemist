<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeTextFormatPluginInterface {

  /**
   * Sets the markup format for the component shape.
   *
   * @param string $textFormat
   *   The markup format to set.
   *
   * @return $this
   */
  public function setTextFormat(string $textFormat): self;

}
