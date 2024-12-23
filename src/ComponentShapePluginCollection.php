<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
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
   * {@inheritdoc}
   */
  public function setInstanceConfiguration($instance_id, array $configuration) {
    if (
      isset($this->pluginInstances[$instance_id]) &&
      isset($configuration[$this->pluginKey]) &&
      isset($this->configurations[$instance_id][$this->pluginKey]) &&
      $configuration[$this->pluginKey] !== $this->configurations[$instance_id][$this->pluginKey]
    ) {
      // If the plugin has already been instantiated by the configuration was
      // for a different plugin then we need to unset the instantiated plugin.
      unset($this->pluginInstances[$instance_id]);
    }

    $this->configurations[$instance_id] = $configuration;
    $instance = $this->get($instance_id);
    if ($instance instanceof ConfigurableInterface) {
      $instance->setConfiguration($configuration['settings'] ?? []);
    }
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
   * Sets the status of a component shape instance.
   *
   * @param string $instance_id
   *   The ID of the component shape instance.
   * @param bool $status
   *   The status to set for the component shape instance.
   */
  public function setStatus($instance_id, bool $status): void {
    $this->configurations[$instance_id]['status'] = $status;
  }

  /**
   * Gets the status of a component shape instance.
   *
   * @param string $instance_id
   *   The ID of the component shape instance.
   *
   * @return bool
   *   The status of the component shape instance.
   */
  public function getStatus($instance_id): bool {
    return $this->configurations[$instance_id]['status'] ?? FALSE;
  }

  /**
   * Get instances.
   *
   * @return \Drupal\neo_alchemist\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getInstances(): array {
    $instances = [];
    foreach ($this->instanceIds as $instanceId) {
      /** @var \Drupal\neo_alchemist\ComponentValuePluginInterface $instance */
      $instances[$instanceId] = $this->get($instanceId);
    }
    return $instances;
  }

  /**
   * Get instances by group.
   *
   * @return \Drupal\neo_alchemist\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getInstancesByGroup(string $groupId): array {
    $instances = [];
    foreach ($this->getInstances() as $instance) {
      if ($instance->getGroup() === $groupId) {
        $instances[$instance->getPluginId()] = $instance;
      }
    }
    return $instances;
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
      $configuration = $this->configurations[$instanceId] ?? [];
      if (!empty($configuration['status'])) {
        $activeInstances[$instanceId] = $this->get($instanceId);
      }
    }
    return $activeInstances;
  }

  /**
   * Get the active instances that are allow for the provided operation.
   *
   * @param string $op
   *   The operation being performed. Current operations are 'default', 'value',
   *   'edit', 'modify' and 'form'.
   *
   * @return \Drupal\neo_alchemist\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getAllowedInstances(string $op): array {
    $instances = [];
    foreach ($this->getActiveInstances() as $instance) {
      if ($instance->isAllowed($op)) {
        // Reset the continue flag.
        $instances[$instance->getPluginId()] = $instance->allowFurtherProcessing();
      }
    }
    return $instances;
  }

}
