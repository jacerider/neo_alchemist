<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;

/**
 * Defines a plugin manager to deal with neo_component_prop_defs.
 *
 * Modules can define neo_component_prop_defs in a
 * MODULE_NAME.neo_component_prop_defs.yml file contained in the module's base
 * directory. Each neo_component_prop_def has the following structure:
 *
 * @code
 *   MACHINE_NAME:
 *     title: STRING
 *     type: STRING
 *     required: ARRAY
 *     properties: ARRAY
 *     format: STRING
 *     pattern: STRING
 *     examples: ARRAY
 * @endcode
 *
 * @see \Drupal\neo_alchemist\PropDefDefault
 * @see \Drupal\neo_alchemist\PropDefInterface
 */
final class ComponentPropDefPluginManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'title' => '',
    'type' => '',
    'required' => [],
    'properties' => [],
    'format' => '',
    'pattern' => '',
    'examples' => [],
  ];

  /**
   * Constructs PropDefPluginManager object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->alterInfo('neo_component_prop_def_info');
    $this->setCacheBackend($cache_backend, 'neo_component_prop_def_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery(): YamlDiscovery {
    if (!isset($this->discovery)) {
      $discovery = new YamlDiscovery('neo_component_prop_defs', $this->moduleHandler->getModuleDirectories());
      $discovery->addTranslatableProperty('title', 'title_context');
      $discovery->addTranslatableProperty('description', 'description_context');
      $this->discovery = $discovery;
    }
    return $this->discovery;
  }

}
