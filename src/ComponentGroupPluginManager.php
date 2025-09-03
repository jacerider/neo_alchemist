<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;

/**
 * Defines a plugin manager to deal with neo_component_groups.
 *
 * Modules can define neo_component_groups in a
 * MODULE_NAME.neo_component_groups.yml file contained in the module's
 * base directory. Each neo_component_group has the following structure:
 *
 * @code
 *   MACHINE_NAME:
 *     label: STRING
 *     description: STRING
 *     weight: INTEGER
 * @endcode
 *
 * @see \Drupal\neo_alchemist\ComponentGroupDefault
 * @see \Drupal\neo_alchemist\ComponentGroupInterface
 */
final class ComponentGroupPluginManager extends DefaultPluginManager {

  /**
   * The object that discovers plugins managed by this manager.
   *
   * @var \Drupal\Core\Plugin\Discovery\YamlDiscovery
   */
  protected $discovery;

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'label' => '',
    'description' => '',
    'weight' => 0,
  ];

  /**
   * Constructs ComponentGroupPluginManager object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->alterInfo('neo_component_group_info');
    $this->setCacheBackend($cache_backend, 'neo_component_group_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery(): YamlDiscovery {
    if (!isset($this->discovery)) {
      $this->discovery = new YamlDiscovery('neo_component_groups', $this->moduleHandler->getModuleDirectories());
      $this->discovery->addTranslatableProperty('label', 'label_context');
      $this->discovery->addTranslatableProperty('description', 'description_context');
    }
    return $this->discovery;
  }

  /**
   * {@inheritdoc}
   */
  protected function findDefinitions() {
    $definitions = parent::findDefinitions();

    // Sort the definitions by their weight while preserving the original order
    // for those with matching weights.
    $weights = array_map(function (array $definition) {
      return $definition['weight'];
    }, $definitions);
    $ids = array_keys($definitions);
    array_multisort($weights, $ids, $definitions);
    return $definitions;
  }

}
