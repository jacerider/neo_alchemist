<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValuePluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Called when a prop is removed from a component.
   */
  public function onPropRemove(): void;

  /**
   * Returns if the provider can be used for the provided shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape that should be checked.
   *
   * @return bool
   *   TRUE if the provider can be used, FALSE otherwise.
   */
  public static function isApplicable(ComponentShapePluginInterface $shape);

}
