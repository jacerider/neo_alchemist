<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValueModifierPluginInterface extends ComponentValuePluginInterface {

  /**
   * Modifies the given value.
   *
   * This method takes a value of any type and returns the modified value.
   *
   * @param mixed $value
   *   The value to be modified.
   *
   * @return mixed
   *   The unmodified value.
   */
  public function modifyValue(mixed $value): mixed;

}
