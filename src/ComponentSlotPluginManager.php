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

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['component'], $configuration['uuid'], $configuration['settings']);
  }

  /**
   * Filters and sorts component definitions based on the provided shape.
   *
   * This method retrieves all component definitions and filters them based on
   * the type, entity type, and bundle specified by the given component. It then
   * sorts the filtered definitions by weight and label.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component interface which provides the type, entity type, and
   *   bundle.
   *
   * @return array
   *   An array of filtered and sorted component definitions.
   */
  public function getFilteredDefinitionsFromComponent(ComponentInterface $component): array {
    $filtered = array_filter($this->getDefinitions(), function ($definition) use ($component) {
      if (!$definition['class']::isApplicable($component)) {
        return FALSE;
      }
      return TRUE;
    });

    // Sort the filtered definitions by weight and label.
    uasort($filtered, function ($a, $b) {
      return $a['label'] <=> $b['label'];
    });

    return $filtered;
  }

}
