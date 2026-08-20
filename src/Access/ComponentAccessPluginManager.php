<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginManagerBase;

/**
 * ComponentAccess plugin manager.
 */
final class ComponentAccessPluginManager extends ConfiguredPluginManagerBase {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentAccess', $namespaces, $module_handler, ComponentAccessPluginInterface::class, ComponentAccess::class);
    $this->alterInfo('neo_component_access_info');
    $this->setCacheBackend($cache_backend, 'neo_component_access_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerKey(): string {
    return 'access';
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerInterface(): string {
    return ComponentAccessInterface::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function newInstance(string $class, string $plugin_id, $plugin_definition, array $configuration): object {
    return new $class($plugin_id, $plugin_definition, $configuration['access'], $configuration['settings']);
  }

}
