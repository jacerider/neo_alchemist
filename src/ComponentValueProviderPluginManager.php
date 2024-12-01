<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;

/**
 * ComponentValueProvider plugin manager.
 */
final class ComponentValueProviderPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentValueProvider', $namespaces, $module_handler, ComponentValueProviderPluginInterface::class, ComponentValueProvider::class);
    $this->alterInfo('neo_component_value_provider_info');
    $this->setCacheBackend($cache_backend, 'neo_component_value_provider_plugins');
  }

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
  public function getFilteredDefinitionsFromShape(ComponentShapePluginInterface $shape) {
    $definitions = $this->getDefinitions();
    $type = $shape->getType();
    $entityTypeId = $shape->getTargetEntityType();
    $bundle = $shape->getTargetEntityBundle();

    $filtered = [];
    $keys = ['*'];
    if ($entityTypeId) {
      $keys[] = "$entityTypeId.*";
      if ($bundle) {
        $keys[] = "$entityTypeId.$bundle";
      }
    }
    foreach ($definitions as $id => $definition) {
      if (empty($definition['entity_types']) || array_intersect($keys, $definition['entity_types'])) {
        if (!empty($definition['prop_type'])) {
          if ($definition['prop_type'] === $type) {
            $filtered[$id] = $definition;
          }
        }
        else {
          $filtered[$id] = $definition;
        }
      }
      else {
        if ($definition['prop_type'] === $type) {
          $filtered[$id] = $definition;
        }
      }
    }
    uasort($filtered, function ($a, $b) {
      $a_weight = $a['weight'] ?? 0;
      $b_weight = $b['weight'] ?? 0;
      if ($a_weight == $b_weight) {
        $a_label = $a['label'];
        $b_label = $b['label'];
        return strnatcasecmp((string) $a_label, (string) $b_label);
      }
      return ($a_weight < $b_weight) ? -1 : 1;
    });
    return $filtered;
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

    // If the plugin provides a factory method, pass the container to it.
    if (is_subclass_of($plugin_class, 'Drupal\Core\Plugin\ContainerFactoryPluginInterface')) {
      return $plugin_class::create(\Drupal::getContainer(), $configuration, $plugin_id, $plugin_definition);
    }

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['shape'], $configuration['settings'] ?? []);
  }

}
