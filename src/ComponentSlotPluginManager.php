<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentSlot;

/**
 * ComponentSlot plugin manager.
 */
final class ComponentSlotPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentSlot', $namespaces, $module_handler, ComponentSlotPluginInterface::class, ComponentSlot::class);
    $this->alterInfo('neo_component_slot_info');
    $this->setCacheBackend($cache_backend, 'neo_component_slot_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

    assert($configuration['component'] instanceof ComponentInterface);

    $configuration += [
      'uuid' => '',
      'settings' => [],
    ];

    // If the plugin provides a factory method, pass the container to it.
    if (is_subclass_of($plugin_class, 'Drupal\Core\Plugin\ContainerFactoryPluginInterface')) {
      return $plugin_class::create(\Drupal::getContainer(), $configuration, $plugin_id, $plugin_definition);
    }

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['component'], $configuration['uuid'], $configuration['settings']);
  }

}
