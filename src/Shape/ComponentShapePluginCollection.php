<?php

namespace Drupal\neo_alchemist\Shape;

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
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   */
  protected $shape;

  /**
   * Constructs a ComponentShapePluginCollection object.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * Reorders the plugin instances.
   *
   * Instances are processed and serialized in instance-ID order, so this is how
   * a saved drag-and-drop order is applied. Instance IDs not present in the
   * provided list keep their current relative order and are appended after the
   * listed ones; unknown IDs are ignored.
   *
   * @param string[] $orderedInstanceIds
   *   Instance IDs in the desired order.
   */
  public function setInstanceOrder(array $orderedInstanceIds): void {
    $reordered = [];
    foreach ($orderedInstanceIds as $instanceId) {
      if (isset($this->instanceIds[$instanceId])) {
        $reordered[$instanceId] = $instanceId;
      }
    }
    foreach ($this->instanceIds as $instanceId) {
      if (!isset($reordered[$instanceId])) {
        $reordered[$instanceId] = $instanceId;
      }
    }
    $this->instanceIds = $reordered;
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
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getInstances(): array {
    $instances = [];
    foreach ($this->instanceIds as $instanceId) {
      /** @var \Drupal\neo_alchemist\Value\ComponentValuePluginInterface $instance */
      $instances[$instanceId] = $this->get($instanceId);
    }
    return $instances;
  }

  /**
   * Get instances by group.
   *
   * @param string $groupId
   *   The group ID.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
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
   * Get instances that support inlining.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getInlineInstances(): array {
    $instances = [];
    foreach ($this->getInstances() as $instance) {
      if ($instance->allowInline()) {
        $instances[$instance->getPluginId()] = $instance;
      }
    }
    return $instances;
  }

  /**
   * Get the active instances.
   *
   * @param string|null $groupId
   *   (optional) Restrict to a single value group. A group is a plugin's sort
   *   weight and its form tab, so this filter is for form placement — e.g.
   *   listing the plugins that belong under one tab. Do NOT use it to ask
   *   whether a plugin sources a value: that is the producer role, answered by
   *   ComponentValuePluginInterface::isValueProducer(), so that choosing a
   *   group for the form cannot silently change behavior.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getActiveInstances(?string $groupId = NULL): array {
    $activeInstances = [];
    foreach ($this->instanceIds as $instanceId) {
      $configuration = $this->configurations[$instanceId] ?? [];
      if (!empty($configuration['status'])) {
        $instance = $this->get($instanceId);
        if ($groupId !== NULL && $instance->getGroup() !== $groupId) {
          continue;
        }
        $activeInstances[$instanceId] = $instance;
      }
    }
    return $activeInstances;
  }

  /**
   * Check if an active instance ID exists.
   *
   * @param string $instance_id
   *   The ID of the plugin instance to check.
   */
  public function hasActiveInstance(string $instance_id): bool {
    $configuration = $this->configurations[$instance_id] ?? [];
    return !empty($configuration['status']);
  }

  /**
   * Get the active instances that are allow for the provided operation.
   *
   * @param string $op
   *   The operation being performed. Current operations are 'default', 'value',
   *   'edit', 'modify' and 'form'.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
   *   The active instances.
   */
  public function getAllowedInstances(string $op): array {
    $instances = [];
    foreach ($this->getActiveInstances() as $instance) {
      if ($instance->isAllowed($op)) {
        $instances[$instance->getPluginId()] = $instance;
      }
    }
    return $instances;
  }

}
