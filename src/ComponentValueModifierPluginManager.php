<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\Attribute\ComponentValueModifier;

/**
 * ComponentValueModifier plugin manager.
 */
final class ComponentValueModifierPluginManager extends ComponentValuePluginManagerBase {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ComponentValueModifier', $namespaces, $module_handler, ComponentValueModifierPluginInterface::class, ComponentValueModifier::class);
    $this->alterInfo('neo_component_value_modifier_info');
    $this->setCacheBackend($cache_backend, 'neo_component_value_modifier_plugins');
  }

  /**
   * {@inheritDoc}
   */
  public function label() {
    return t('Value Modifiers');
  }

}
