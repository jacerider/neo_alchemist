<?php

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\Component\SchemaCompatibilityChecker;
use Drupal\Core\Theme\ComponentNegotiator;
use Drupal\Core\Theme\ComponentPluginManager as ThemeComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Defines a plugin manager to deal with components.
 */
class ComponentPluginManager extends ThemeComponentPluginManager {

  /**
   * The prop def manager.
   *
   * @var \Drupal\neo_alchemist\ComponentPropDefPluginManager
   */
  protected ComponentPropDefPluginManager $propDefManager;

  /**
   * Constructs ComponentPluginManager object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\Core\Theme\ComponentNegotiator $componentNegotiator
   *   The component negotiator.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Theme\Component\SchemaCompatibilityChecker $compatibilityChecker
   *   The compatibility checker.
   * @param \Drupal\Core\Theme\Component\ComponentValidator $componentValidator
   *   The component validator.
   * @param string $appRoot
   *   The application root.
   * @param \Drupal\neo_alchemist\ComponentPropDefPluginManager $prop_def_manager
   *   The prop def manager.
   *
   * @phpstan-ignore-next-line
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    protected ThemeHandlerInterface $themeHandler,
    CacheBackendInterface $cacheBackend,
    protected ConfigFactoryInterface $configFactory,
    protected ThemeManagerInterface $themeManager,
    protected ComponentNegotiator $componentNegotiator,
    protected FileSystemInterface $fileSystem,
    protected SchemaCompatibilityChecker $compatibilityChecker,
    protected ComponentValidator $componentValidator,
    protected string $appRoot,
    protected ComponentPropDefPluginManager $prop_def_manager
  ) {
    parent::__construct($module_handler, $themeHandler, $cacheBackend, $configFactory, $themeManager, $componentNegotiator, $fileSystem, $compatibilityChecker, $componentValidator, $appRoot);
    $this->propDefManager = $prop_def_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterDefinitions(&$definitions) {
    foreach ($definitions as $id => &$definition) {
      if (!empty($definition['props']['properties'])) {
        $definition['props']['properties'] = array_map([$this, 'alterProp'], $definition['props']['properties']);
      }
    }
    parent::alterDefinitions($definitions);
  }

  /**
   * Alters a prop definition.
   *
   * @param array $prop
   *   The prop definition.
   *
   * @return array
   *   The altered prop definition.
   */
  protected function alterProp(array $prop): array {
    $propDefinitions = $this->propDefManager->getDefinitions();
    if (isset($propDefinitions[$prop['type']])) {
      $prop['ref'] = $prop['type'];
      $propDef = $propDefinitions[$prop['type']];
      $propRequired = [
        'type' => [$propDef['type']],
        'format' => $propDef['format'],
        'pattern' => $propDef['pattern'],
      ];
      $propOptional = [];
      if ($propDef['properties']) {
        $propRequired['properties'] = array_map([__CLASS__, 'alterProp'], $propDef['properties']);
      }
      if ($propDef['required']) {
        $propRequired['required'] = $propDef['required'];
      }
      if ($propDef['examples']) {
        $propOptional['examples'] = $propDef['examples'];
      }
      $prop = $propRequired + $prop + $propOptional;
    }
    if (!empty($prop['items']['properties'])) {
      $prop['items']['properties'] = array_map([__CLASS__, 'alterProp'], $prop['items']['properties']);
    }
    return $prop;
  }

}
