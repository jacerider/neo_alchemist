<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

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
   * Get every value group id, ordered by the group's weight.
   *
   * A group states the role a plugin plays in producing a prop's value, so this
   * order is what makes a `providers` plugin run before the terminal `fallback`
   * regardless of the order the site builder happened to enable them in.
   *
   * @return string[]
   *   The group ids, ordered by group weight.
   */
  public function getGroupOrder(): array;

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
