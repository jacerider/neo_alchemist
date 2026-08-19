<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\Attribute\ComponentSlot;

/**
 * ComponentSlot plugin manager.
 */
final class ComponentSlotPluginManager extends ConfiguredPluginManagerBase {

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
  protected function ownerKey(): string {
    return 'component';
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerInterface(): string {
    return ComponentInterface::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function instanceConfigurationDefaults(): array {
    return [
      'uuid' => '',
      'settings' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function newInstance(string $class, string $plugin_id, $plugin_definition, array $configuration): object {
    return new $class($plugin_id, $plugin_definition, $configuration['component'], $configuration['uuid'], $configuration['settings']);
  }

}
