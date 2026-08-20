<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * ComponentValue plugin manager.
 */
final class ComponentValuePluginManager extends DefaultPluginManager implements ComponentValuePluginManagerInterface {

  /**
   * The group manager.
   *
   * @var \Drupal\neo_alchemist\Value\ComponentValueGroupPluginManager
   */
  protected ComponentValueGroupPluginManager $groupManager;

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, ComponentValueGroupPluginManager $group_manager) {
    parent::__construct('Plugin/ComponentValue', $namespaces, $module_handler, ComponentValuePluginInterface::class, ComponentValue::class);
    $this->alterInfo('neo_component_value_info');
    $this->setCacheBackend($cache_backend, 'neo_component_value_plugins');
    $this->groupManager = $group_manager;
  }

  /**
   * {@inheritDoc}
   */
  public function label() {
    return new TranslatableMarkup('NOT NEEDED');
  }

  /**
   * Performs extra processing on plugin definitions.
   *
   * By default we add defaults for the type to the definition. If a type has
   * additional processing logic they can do that by replacing or extending the
   * method.
   */
  public function processDefinition(&$definition, $plugin_id) {
    parent::processDefinition($definition, $plugin_id);
    $definition['group'] = $definition['group'] ?? 'providers';
    $definition['inline'] = !empty($definition['inline']);

    if (!$this->groupManager->hasDefinition($definition['group'])) {
      throw new \InvalidArgumentException(sprintf('The group %s does not exist.', $definition['group']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);

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

    return new $plugin_class($plugin_id, $plugin_definition, $configuration['shape'], $configuration['settings'] ?? []);
  }

  /**
   * {@inheritDoc}
   */
  public function getGroupOrder(): array {
    $groups = $this->groupManager->getDefinitions();
    uasort($groups, [SortArray::class, 'sortByWeightElement']);
    return array_keys($groups);
  }

  /**
   * Filters and sorts component definitions based on the provided shape.
   *
   * This method retrieves all component definitions and filters them based on
   * the type, entity type, and bundle specified by the given shape. It then
   * sorts the filtered definitions by weight and label.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin interface which provides the type, entity type, and
   *   bundle.
   *
   * @return array
   *   An array of filtered and sorted component definitions.
   */
  public function getFilteredDefinitionsFromShape(ComponentShapePluginInterface $shape): array {
    $filtered = array_filter($this->getDefinitions(), function ($definition) use ($shape) {
      if (!$definition['class']::isApplicable($shape)) {
        return FALSE;
      }
      if (!$shape->allowValuePlugin($definition)) {
        return FALSE;
      }
      if (!empty($definition['entity_types'])) {
        $entityTypeId = $shape->getTargetEntityType();
        $bundle = $shape->getTargetEntityBundle();
        $keys = [];
        if ($entityTypeId) {
          $keys[] = '*';
          $keys[] = "$entityTypeId.*";
          if ($bundle) {
            $keys[] = "$entityTypeId.$bundle";
          }
        }
        $include = array_filter($definition['entity_types'], fn ($entityType) => substr($entityType, 0, 1) !== '!');
        $exclude = array_map(fn ($entityType) => substr($entityType, 1), array_filter($definition['entity_types'], fn ($entityType) => substr($entityType, 0, 1) === '!'));
        if ($include && !array_intersect($keys, $include)) {
          return FALSE;
        }
        if ($exclude && array_intersect($keys, $exclude)) {
          return FALSE;
        }
      }
      if (!empty($definition['prop_types'])) {
        $type = $shape->getType();
        $include = array_filter($definition['prop_types'], fn ($propType) => substr($propType, 0, 1) !== '!');
        $exclude = array_map(fn ($propType) => substr($propType, 1), array_filter($definition['prop_types'], fn ($propType) => substr($propType, 0, 1) === '!'));
        if ($include && !in_array($type, $include)) {
          return FALSE;
        }
        if ($exclude && in_array($type, $exclude)) {
          return FALSE;
        }
      }
      if (!empty($definition['ref_types'])) {
        $type = $shape->getRef();
        $include = array_filter($definition['ref_types'], fn ($propType) => substr($propType, 0, 1) !== '!');
        $exclude = array_map(fn ($propType) => substr($propType, 1), array_filter($definition['ref_types'], fn ($propType) => substr($propType, 0, 1) === '!'));
        if ($include && !in_array($type, $include)) {
          return FALSE;
        }
        if ($exclude && in_array($type, $exclude)) {
          return FALSE;
        }
      }
      return TRUE;
    });
    uasort($filtered, function ($a, $b) {
      $a_weight = $a['weight'] ?? 0;
      $b_weight = $b['weight'] ?? 0;
      if ($a_weight == $b_weight) {
        $a_label = $a['label'];
        $b_label = $b['label'];
        return strnatcasecmp((string) $a_label, (string) $b_label);
      }
      return ($a_weight < $b_weight) ? -1 : 1;
    });
    return $filtered;
  }

}
