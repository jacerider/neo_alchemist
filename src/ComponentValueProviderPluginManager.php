<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;

/**
 * ComponentValueProvider plugin manager.
 */
final class ComponentValueProviderPluginManager extends ComponentValuePluginOldManagerBase {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentValueProvider', $namespaces, $module_handler, ComponentValueProviderPluginInterface::class, ComponentValueProvider::class);
    $this->alterInfo('neo_component_value_provider_info');
    $this->setCacheBackend($cache_backend, 'neo_component_value_provider_plugins');
  }

  /**
   * {@inheritDoc}
   */
  public function label() {
    return t('Value Providers');
  }

}
