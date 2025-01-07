<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentStyle;

/**
 * ComponentStyle plugin manager.
 */
final class ComponentStylePluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentStyle', $namespaces, $module_handler, ComponentStyleInterface::class, ComponentStyle::class);
    $this->alterInfo('neo_component_style_info');
    $this->setCacheBackend($cache_backend, 'neo_component_style_plugins');
  }

}
