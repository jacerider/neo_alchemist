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
    $type = is_array($prop['type']) ? reset($prop['type']) : $prop['type'];
    if (isset($propDefinitions[$type])) {
      $prop['ref'] = $type;
      $propDef = $propDefinitions[$type];
      $propRequired = [
        'type' => $propDef['type'],
        'format' => $propDef['format'],
        'pattern' => $propDef['pattern'],
      ];
      $propOptional = [
        'title' => $propDef['title'] ?? '',
        'description' => $propDef['description'] ?? '',
      ];
      $propOptional = array_diff_key($propDefinitions[$type], array_flip([
        'id',
        'provider',
        'properties',
        'required',
      ]));
      if ($propDef['properties']) {
        $propRequired['properties'] = array_map([__CLASS__, 'alterProp'], $propDef['properties']);
      }
      if ($propDef['required']) {
        $propRequired['required'] = $propDef['required'];
      }
      $prop = $propRequired + $prop + $propOptional;
      $propDefType = $propDef['type'];
      if (is_array($propDefType)) {
        $propDefType = reset($propDefType);
      }
      if (isset($propDefinitions[$propDefType])) {
        $prop = $this->alterProp($prop);
      }
    }
    elseif (isset($prop['properties'])) {
      $prop['properties'] = array_map([__CLASS__, 'alterProp'], $prop['properties']);
    }
    if (!empty($prop['items']['properties'])) {
      $prop['items']['properties'] = array_map([__CLASS__, 'alterProp'], $prop['items']['properties']);
    }
    elseif (!empty($prop['items'])) {
      $prop['items'] = array_map([__CLASS__, 'alterProp'], ['items' => $prop['items']])['items'];
    }

    // Gracefully handle a prop whose type references a prop def (or class) that
    // does not exist on this site — e.g. an SDC pulled from another project that
    // used a custom prop type. Left unresolved, core's ComponentValidator treats
    // the unknown type as a class name and throws an InvalidComponentException,
    // breaking the whole component. Fall back to a permissive string so the
    // component still loads; the prop simply gets no specialized widget/handling
    // until the missing prop definition is provided.
    $rootType = is_array($prop['type'] ?? NULL) ? reset($prop['type']) : ($prop['type'] ?? NULL);
    if (is_string($rootType) && $rootType !== ''
      && !in_array($rootType, ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'], TRUE)
      && !isset($propDefinitions[$rootType])
      && !class_exists($rootType)
      && !interface_exists($rootType)
    ) {
      \Drupal::logger('neo_alchemist')->warning('Unknown component prop type %type; falling back to "string". A module or theme providing this prop definition may be missing.', ['%type' => $rootType]);
      $prop['type'] = 'string';
      unset($prop['ref']);
    }

    return $prop;
  }

  /**
   * Gets the root type of a prop.
   *
   * @param string $type
   *   The prop type.
   * @param array $propDefinitions
   *   The prop definitions.
   *
   * @return string
   *   The root type.
   */
  protected function getRootType($type, $propDefinitions) {
    if (isset($propDefinitions[$type])) {
      return $this->getRootType($propDefinitions[$type]['type'], $propDefinitions);
    }
    return $type;
  }

  /**
   * Gets the root type of a prop.
   *
   * @param string $type
   *   The prop type.
   * @param array $propDefinitions
   *   The prop definitions.
   *
   * @return string
   *   The root type.
   */
  protected function getRootRef($type, $propDefinitions) {
    if (isset($propDefinitions[$type])) {
      return $this->getRootType($propDefinitions[$type]['type'], $propDefinitions);
    }
    return $type;
  }

}
