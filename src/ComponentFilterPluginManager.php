<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\Attribute\ComponentFilter;

/**
 * ComponentFilter plugin manager.
 */
final class ComponentFilterPluginManager extends ConfiguredPluginManagerBase {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentFilter', $namespaces, $module_handler, ComponentFilterPluginInterface::class, ComponentFilter::class);
    $this->alterInfo('neo_component_filter_info');
    $this->setCacheBackend($cache_backend, 'neo_component_filter_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerKey(): string {
    return 'filter';
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerInterface(): string {
    return ComponentFilterInterface::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function newInstance(string $class, string $plugin_id, $plugin_definition, array $configuration): object {
    return new $class($plugin_id, $plugin_definition, $configuration['filter'], $configuration['settings']);
  }

}
