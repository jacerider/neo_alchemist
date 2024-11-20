<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityInterface;
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
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param array $values
   *   The value overrides.
   * @param array $props
   *   The props.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The instances.
   */
  public function getInstancesFromSchema(array $schema, ContentEntityInterface $entity, array $values = [], array $propSettings = []): array {
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
        if ($shape = $this->getInstance([
          'schema' => $prop,
          'entity' => $entity,
          'required' => $required,
        ])) {
          // if ($propName === 'image') {
          //   $shape->setWidget('neo_options_buttons');
          // }
          if (isset($propSettings[$propName]['field_type']) && $propSettings[$propName]['field_type'] === $shape->getFieldType()) {
            // Type-check provided prop configuration and use providers.
            foreach ($propSettings[$propName]['providers'] as $provider) {
              $shape->addValueProvider($provider['plugin'], $provider['settings']);
            }
          }
          // Make sure we match the stored field type with the prop field type.
          if (isset($values['props'][$propName]) && $values['props'][$propName]['field_type'] === $shape->getFieldType()) {
            if (isset($values['props'][$propName]['value'])) {
              // ksm('set', $propName);
              $shape->setOverrideValue($values['props'][$propName]['value']);
              // $shape->setFieldItemValue($values['props'][$propName]['value']);
            }
          }
          $shape->calculateFieldItemValue();
          $instances[$propName] = $shape;
        }
      }
    }
    return $instances;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The instance.
   */
  public function getInstance(array $options): ?ComponentShapePluginInterface {
    $options += [
      'schema' => [],
      'entity' => NULL,
      'required' => FALSE,
    ];
    $schema = $options['schema'];
    $type = is_array($schema['type']) ? $schema['type'][0] : $schema['type'];
    $configuration['schema'] = $schema;
    $configuration['entity'] = $options['entity'];
    $configuration['required'] = $options['required'];
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

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['schema'], $configuration['entity'], $configuration['required']);
  }

}
