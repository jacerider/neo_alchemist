<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValueModifierPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

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
