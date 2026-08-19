<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\Attribute\ComponentAccess;

/**
 * ComponentAccess plugin manager.
 */
final class ComponentAccessPluginManager extends DefaultPluginManager {

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
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

    assert($configuration['access'] instanceof ComponentAccessInterface);

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

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['access'], $configuration['settings']);
  }

  /**
   * Gets the definitions applicable to the given component.
   *
   * Filters via each plugin class's static isApplicable() — e.g. the
   * entity_field_value plugin is only offered on components registered
   * against an entity type. Mirrors
   * ComponentSlotPluginManager::getFilteredDefinitionsFromComponent().
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component an access rule would be attached to.
   *
   * @return array
   *   The applicable plugin definitions, sorted by label.
   */
  public function getFilteredDefinitionsFromComponent(ComponentInterface $component): array {
    $filtered = array_filter($this->getDefinitions(), function ($definition) use ($component) {
      return $definition['class']::isApplicable($component);
    });
    uasort($filtered, function ($a, $b) {
      return $a['label'] <=> $b['label'];
    });
    return $filtered;
  }

}
