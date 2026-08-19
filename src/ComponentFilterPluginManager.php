<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentFilter;

/**
 * ComponentFilter plugin manager.
 */
final class ComponentFilterPluginManager extends DefaultPluginManager {

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
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

    assert($configuration['filter'] instanceof ComponentFilterInterface);

    $configuration += [
      'settings' => [],
    ];

    // If the plugin provides a factory method, pass the container to it.
    if (is_subclass_of($plugin_class, 'Drupal\Core\Plugin\ContainerFactoryPluginInterface')) {
      // A plugin factory is the one place a container legitimately belongs,
      // and these managers cannot delegate to ContainerFactory: each family's
      // plugins take a bespoke constructor (a shape, an access rule, a slot,
      // a filter) that DefaultFactory cannot produce. Core makes exactly this
      // call for exactly this reason; injecting the container as a service
      // instead would be a service locator, and a worse one.
      //
      // @see \Drupal\Core\Plugin\Factory\ContainerFactory::createInstance()
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      return $plugin_class::create(\Drupal::getContainer(), $configuration, $plugin_id, $plugin_definition);
    }

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['filter'], $configuration['settings']);
  }

}
