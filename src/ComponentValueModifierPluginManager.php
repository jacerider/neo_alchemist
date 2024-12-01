<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentValueModifier;

/**
 * ComponentValueModifier plugin manager.
 */
final class ComponentValueModifierPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentValueModifier', $namespaces, $module_handler, ComponentValueModifierPluginInterface::class, ComponentValueModifier::class);
    $this->alterInfo('neo_component_value_modifier_info');
    $this->setCacheBackend($cache_backend, 'neo_component_value_modifier_plugins');
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
    $keys = [];
    if ($entityTypeId) {
      $keys[] = '*';
      $keys[] = "$entityTypeId.*";
      if ($bundle) {
        $keys[] = "$entityTypeId.$bundle";
      }
    }
    foreach ($definitions as $id => $definition) {
      if (empty($definition['entity_types']) || array_intersect($keys, $definition['entity_types'])) {
        if (!empty($definition['prop_types'])) {
          if (in_array($type, $definition['prop_types'])) {
            $filtered[$id] = $definition;
          }
        }
        else {
          $filtered[$id] = $definition;
        }
      }
      else {
        if (in_array($type, $definition['prop_types'])) {
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
