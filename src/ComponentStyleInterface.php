<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_style plugins.
 */
interface ComponentStyleInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

}
