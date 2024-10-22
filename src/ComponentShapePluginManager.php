<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * ComponentShape plugin manager.
 */
final class ComponentShapePluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentShape', $namespaces, $module_handler, ComponentShapePluginInterface::class, ComponentShape::class);
    $this->alterInfo('neo_component_shape_info');
    $this->setCacheBackend($cache_backend, 'neo_component_shape_plugins');
  }

  /**
   * Get instances from schema.
   *
   * @param array $schema
   *   The schema.
   * @param array $defaults
   *   The defaults.
   * @param string $entityType
   *   The entity type.
   * @param string $entityBundle
   *   The entity bundle.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The instances.
   */
  public function getInstancesFromSchema(array $schema, array $defaults = [], $entityType = '', $entityBundle = ''): array {
    $instances = [];
    if (!empty($schema['properties'])) {
      foreach ($schema['properties'] as $propName => $prop) {
        // TRICKY: `attributes` is a special case — it is kind of a reserved
        // prop.
        // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
        // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
        if ($propName === 'attributes') {
          assert($prop['type'][0] === Attribute::class);
          continue;
        }
        $prop['name'] = $propName;
        $prop['type'] = is_array($prop['type']) ? $prop['type'] : [$prop['type']];
        if (isset($prop['examples']) && is_array($prop['examples']) && !in_array('array', $prop['type'])) {
          $prop['examples'] = $prop['examples'][0] ?? $prop['examples'];
        }
        $required = in_array($propName, $schema['required'] ?? [], TRUE);
        if ($shape = $this->getInstance($prop, $required)) {
          $shape->setEntityType($entityType, $entityBundle);
          // if ($propName === 'image') {
          //   $shape->setWidget('neo_options_buttons');
          // }
          // Make sure we match the stored field type with the prop field type.
          if (isset($defaults['props'][$propName]) && $defaults['props'][$propName]['field_type'] === $shape->getFieldType()) {
            if (isset($defaults['props'][$propName]['default_value'])) {
              $shape->setFieldItemValue($defaults['props'][$propName]['default_value']);
            }
          }
          $instances[$propName] = $shape;
        }
      }
    }
    return $instances;
  }

  /**
   * Get an instance of a component shape plugin.
   *
   * @param array $schema
   *   The schema.
   * @param bool $required
   *   Whether the shape is required.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The instance.
   */
  public function getInstance(array $schema, $required = FALSE): ?ComponentShapePluginInterface {
    $type = is_array($schema['type']) ? $schema['type'][0] : $schema['type'];
    $configuration['schema'] = $schema;
    $configuration['required'] = $required;
    if (!empty($schema['ref']) && $this->hasDefinition($schema['ref'])) {
      $type = $schema['ref'];
    }
    return $this->hasDefinition($type) ? $this->createInstance($type, $configuration) : NULL;
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

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['schema'], $configuration['required']);
  }

}
