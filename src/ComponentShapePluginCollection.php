<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;

/**
 * A class which wraps the displays of a view so you can lazy-initialize them.
 */
class ComponentShapePluginCollection extends DefaultLazyPluginCollection {

  /**
   * Stores a reference to the shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected $shape;

  /**
   * Constructs a ComponentShapePluginCollection object.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape which has this plugins attached.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The manager to be used for instantiating plugins.
   * @param array $configurations
   *   (optional) An associative array containing the initial configuration for
   *   each plugin in the collection, keyed by plugin instance ID.
   */
  public function __construct(ComponentShapePluginInterface $shape, PluginManagerInterface $manager, array $configurations = []) {
    parent::__construct($manager, $configurations);
    $this->shape = $shape;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializePlugin($instance_id) {
    $configuration = $this->configurations[$instance_id] ?? [];
    $configuration['shape'] = $this->shape;
    if (!isset($configuration[$this->pluginKey])) {
      throw new PluginNotFoundException($instance_id);
    }
    $this->set($instance_id, $this->manager->createInstance($configuration[$this->pluginKey], $configuration));
  }

  /**
   * Check if an instance ID exists.
   *
   * @param string $instance_id
   *   The ID of the plugin instance to check.
   */
  public function hasInstanceId($instance_id): bool {
    return isset($this->instanceIds[$instance_id]);
  }

  /**
   * Get the active instances.
   *
   * @return \Drupal\neo_alchemist\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getActiveInstances(): array {
    $activeInstances = [];
    foreach ($this->instanceIds as $instanceId) {
      /** @var \Drupal\neo_alchemist\ComponentValuePluginInterface $instance */
      $instance = $this->get($instanceId);
      if ($instance->getConfiguration()['status'] ?? FALSE) {
        $activeInstances[$instanceId] = $instance;
      }
    }
    return $activeInstances;
  }

}
