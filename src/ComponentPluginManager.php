<?php

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * Whether the plugin manager is recursing.
   *
   * @var bool
   */
  protected static bool $isRecursing = FALSE;

  /**
   * {@inheritdoc}
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
    protected readonly ComponentPropDefPluginManager $propDefManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($module_handler, $themeHandler, $cacheBackend, $configFactory, $themeManager, $componentNegotiator, $fileSystem, $compatibilityChecker, $componentValidator, $appRoot);
  }

  /**
   * {@inheritdoc}
   */
  protected function setCachedDefinitions($definitions): array {
    parent::setCachedDefinitions($definitions);

    // Do not auto-create/update XB configuration when syncing config/deploying.
    // @todo Introduce a "XB development mode" similar to Twig's: https://www.drupal.org/node/3359728
    // @phpstan-ignore-next-line
    if (\Drupal::isConfigSyncing()) {
      return $definitions;
    }

    // TRICKY: Component::save() calls SdcPropKeysConstraintValidator, which
    // will also call this plugin manager! Avoid recursively creating Component
    // config entities.
    if (self::$isRecursing) {
      return $definitions;
    }
    self::$isRecursing = TRUE;

    // $components = $this->entityType
    /** @var \Drupal\neo_alchemist\ComponentInterface[] $components */
    $components = $this->entityTypeManager->getStorage('neo_component')->loadMultiple();
    foreach ($components as $component) {
      if ($component->getExpression() !== $component->generateExpression()) {
        $component->save();
      }
    }

    self::$isRecursing = FALSE;

    return $definitions;
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
    elseif (isset($prop['properties'])) {
      $prop['properties'] = array_map([__CLASS__, 'alterProp'], $prop['properties']);
    }
    if (!empty($prop['items']['properties'])) {
      $prop['items']['properties'] = array_map([__CLASS__, 'alterProp'], $prop['items']['properties']);
    }
    return $prop;
  }

}
