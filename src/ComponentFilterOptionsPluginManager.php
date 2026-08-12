<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\ContainerDerivativeDiscoveryDecorator;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;

/**
 * Defines a plugin manager for component filter option sets.
 *
 * Modules can declare option sets in a
 * MODULE_NAME.neo_component_filter_options.yml file contained in the module's
 * base directory. Each set becomes an "Options" ComponentFilter derivative
 * (plugin id "options:MACHINE_NAME") — a fixed select a site builder can
 * expose as a per-placement filter. Each set has the following structure:
 *
 * @code
 *   MACHINE_NAME:
 *     title: STRING
 *     options:
 *       VALUE: LABEL
 *       VALUE: LABEL
 * @endcode
 *
 * The title is translatable; add title_context to disambiguate. The stored
 * filter value is the selected VALUE key, validated against the set on read
 * (a removed option degrades to the filter's default rather than leaking a
 * stale key).
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentFilter\OptionFilter
 * @see \Drupal\neo_alchemist\Plugin\Derivative\OptionFilterDeriver
 */
final class ComponentFilterOptionsPluginManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'title' => '',
    'options' => [],
  ];

  /**
   * Constructs a ComponentFilterOptionsPluginManager object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->alterInfo('neo_component_filter_options_info');
    $this->setCacheBackend($cache_backend, 'neo_component_filter_options_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery() {
    if (!isset($this->discovery)) {
      $discovery = new YamlDiscovery('neo_component_filter_options', $this->moduleHandler->getModuleDirectories());
      $discovery->addTranslatableProperty('title', 'title_context');
      $this->discovery = new ContainerDerivativeDiscoveryDecorator($discovery);
    }
    return $this->discovery;
  }

  /**
   * Invokes the hook to alter the definitions if the alter hook is set.
   *
   * @param array $definitions
   *   The discovered plugin definitions.
   */
  protected function alterDefinitions(&$definitions) {
    if ($this->alterHook) {
      $this->moduleHandler->alter($this->alterHook, $definitions);
    }
  }

}
