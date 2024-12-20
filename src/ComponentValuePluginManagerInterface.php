<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValuePluginManagerInterface extends PluginManagerInterface, CachedDiscoveryInterface, CacheableDependencyInterface {

  /**
   * Get the label of the plugin type.
   *
   * @return string
   *   The label of the plugin type.
   */
  public function label();

  /**
   * Filters and sorts component definitions based on the provided shape.
   *
   * This method retrieves all component definitions and filters them based on
   * the type, entity type, and bundle specified by the given shape. It then
   * sorts the filtered definitions by weight and label.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin interface which provides the type, entity type, and
   *   bundle.
   *
   * @return array
   *   An array of filtered and sorted component definitions.
   */
  public function getFilteredDefinitionsFromShape(ComponentShapePluginInterface $shape): array;

}
