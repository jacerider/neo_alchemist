<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Base for the managers whose plugins are configured onto a component.
 *
 * Access rules, filters and slots are the same kind of thing: a plugin picked
 * from a list, configured, and stored on a `neo_component`. Two facts about
 * that kind were owned once per family and drifted.
 *
 * The first is instantiation. Each family's plugins take a bespoke constructor
 * that `DefaultFactory` cannot produce, so all three managers overrode
 * ::createInstance() and all three carried the same twelve-line explanation of
 * why a container reaches a plugin factory. A subclass now supplies only what
 * actually differs: the key its owner arrives under, and the constructor call.
 *
 * The second is narrowing. `getFilteredDefinitionsFromComponent()` existed on
 * the slot manager, was copied to the access manager — whose docblock said so —
 * and was never added to the filter manager. The filter form therefore fell
 * back to listing every definition, and a site builder could configure a filter
 * the component does not support, which then did nothing. Owning the method
 * here is what makes a fourth family unable to ship without it.
 */
abstract class ConfiguredPluginManagerBase extends DefaultPluginManager {

  /**
   * The configuration key this family's owner object arrives under.
   *
   * @return string
   *   'access', 'filter' or 'component'.
   */
  abstract protected function ownerKey(): string;

  /**
   * The interface the owner object must satisfy.
   *
   * @return string
   *   A fully qualified interface name.
   */
  abstract protected function ownerInterface(): string;

  /**
   * Configuration every instance of this family starts from.
   *
   * @return array
   *   Defaults merged under the caller's configuration.
   */
  protected function instanceConfigurationDefaults(): array {
    return ['settings' => []];
  }

  /**
   * Constructs a plugin of this family from its bespoke constructor.
   *
   * @param string $class
   *   The plugin class.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param array $configuration
   *   The configuration, with ::instanceConfigurationDefaults() applied.
   *
   * @return object
   *   The plugin instance.
   */
  abstract protected function newInstance(string $class, string $plugin_id, $plugin_definition, array $configuration): object;

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

    $ownerKey = $this->ownerKey();
    $ownerInterface = $this->ownerInterface();
    assert(($configuration[$ownerKey] ?? NULL) instanceof $ownerInterface);

    $configuration += $this->instanceConfigurationDefaults();

    // If the plugin provides a factory method, pass the container to it.
    if (is_subclass_of($plugin_class, ContainerFactoryPluginInterface::class)) {
      // A plugin factory is the one place a container legitimately belongs,
      // and these managers cannot delegate to ContainerFactory: each family's
      // plugins take a bespoke constructor (an access rule, a slot, a filter)
      // that DefaultFactory cannot produce. Core makes exactly this call for
      // exactly this reason; injecting the container as a service instead
      // would be a service locator, and a worse one.
      //
      // @see \Drupal\Core\Plugin\Factory\ContainerFactory::createInstance()
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      return $plugin_class::create(\Drupal::getContainer(), $configuration, $plugin_id, $plugin_definition);
    }

    return $this->newInstance($plugin_class, $plugin_id, $plugin_definition, $configuration);
  }

  /**
   * Gets the definitions applicable to the given component.
   *
   * Filters via each plugin class's static isApplicable() — e.g. the
   * entity_field_value access rule is only offered on components registered
   * against an entity type — so a site builder is never offered a plugin whose
   * configuration would do nothing.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component the plugin would be attached to.
   *
   * @return array
   *   The applicable plugin definitions, sorted by label.
   */
  public function getFilteredDefinitionsFromComponent(ComponentInterface $component): array {
    $filtered = array_filter(
      $this->getDefinitions(),
      static fn (array $definition): bool => $definition['class']::isApplicable($component)
    );
    uasort($filtered, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);
    return $filtered;
  }

}
