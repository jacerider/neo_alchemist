<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Slot;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\Exception\UnknownExtensionTypeException;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_build\NeoBuild;

/**
 * Finds the optional per-slot Twig template shipped inside a component.
 *
 * A component may place `slots/<slot name>.twig` next to its `.component.yml`
 * to take over the markup of that slot's contents. The file is invisible to
 * core's SDC discovery, which resolves a component's template by exact filename
 * (`ComponentPluginManager::findAsset()`), and it needs no registration: Twig's
 * FilesystemLoader already maps `@<extension>` to the extension root as well as
 * to its `templates/` directory, so `@front/components/foo/slots/bar.twig`
 * resolves out of the box.
 *
 * This class exists because there is nowhere else to hang the lookup.
 * ComponentPluginManager deliberately does not implement `alterInfo()` — "we
 * want to ensure that everything related to a component is in the single
 * directory" — so there is no hook_component_info_alter to extend the
 * definition with, and the answer has to be cached separately.
 *
 * @see \Drupal\Core\Theme\ComponentPluginManager::findAsset()
 * @see \Drupal\Core\Template\Loader\FilesystemLoader::__construct()
 * @see \Drupal\neo_alchemist\Slot\ComponentSlot::toRenderable()
 */
final class ComponentSlotTemplateLocator {

  /**
   * The directory, inside a component, holding its slot templates.
   */
  public const DIRECTORY = 'slots';

  /**
   * The cache id of the whole component/slot map.
   */
  private const CID = 'neo_alchemist:slot_templates';

  /**
   * The cache tag the map is invalidated by.
   */
  public const CACHE_TAG = 'neo_alchemist_slot_templates';

  /**
   * The config split whose being active marks a local development checkout.
   */
  private const DEV_SPLIT = 'config_split.config_split.dev';

  /**
   * The memoized map, keyed by component id then slot name.
   *
   * @var array<string, array<string, string>>|null
   */
  private ?array $map = NULL;

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $appRoot,
    private readonly ?NeoBuild $neoBuild = NULL,
  ) {}

  /**
   * Returns the Twig reference of a slot's template.
   *
   * @param string $componentId
   *   The SDC component id, e.g. `front:list_insight`.
   * @param string $slotName
   *   The slot machine name.
   *
   * @return string|null
   *   A namespaced Twig path, or NULL when the component ships no template for
   *   this slot.
   */
  public function getTemplate(string $componentId, string $slotName): ?string {
    return $this->getMap()[$componentId][$slotName] ?? NULL;
  }

  /**
   * Returns every slot template a component ships.
   *
   * @param string $componentId
   *   The SDC component id.
   *
   * @return string[]
   *   Namespaced Twig paths, keyed by slot machine name.
   */
  public function getTemplates(string $componentId): array {
    return $this->getMap()[$componentId] ?? [];
  }

  /**
   * Returns the absolute slot-template directory of a component.
   *
   * The directory need not exist. Used by the Drush inspector and validator to
   * report where a template should be written.
   *
   * @param string $componentId
   *   The SDC component id.
   *
   * @return string|null
   *   The absolute directory, or NULL for an unknown component.
   */
  public function getDirectory(string $componentId): ?string {
    if (!$this->componentPluginManager->hasDefinition($componentId)) {
      return NULL;
    }
    $definition = $this->componentPluginManager->getDefinition($componentId);
    if (empty($definition['path'])) {
      return NULL;
    }
    return $definition['path'] . '/' . self::DIRECTORY;
  }

  /**
   * Whether this is somebody's working checkout rather than a deployed site.
   *
   * Mirrors SdcThumbnailWriter::isEnabled(). Both signals are soft: neo_build
   * is not a declared dependency, and config_split need not be installed, in
   * which case the answer is "not a development environment" — the safe
   * default.
   *
   * Used for two things: bypassing the cache, so a freshly added slot template
   * is picked up without a rebuild while `npm start` is running; and emitting
   * the HTML comments that tell a developer what to name their template.
   *
   * @return bool
   *   TRUE in a development environment.
   *
   * @see \Drupal\neo_alchemist\SdcThumbnailWriter::isEnabled()
   */
  public function isDevMode(): bool {
    if ($this->neoBuild?->isDevMode()) {
      return TRUE;
    }
    // Read through the config factory rather than the entity, so the override
    // in settings.local.php is what counts — the stored entity says FALSE.
    return (bool) $this->configFactory->get(self::DEV_SPLIT)->get('status');
  }

  /**
   * Invalidates the cached map.
   */
  public function reset(): void {
    $this->map = NULL;
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Returns the component/slot map, building it if necessary.
   *
   * @return array<string, array<string, string>>
   *   Namespaced Twig paths, keyed by component id then slot name.
   */
  private function getMap(): array {
    if ($this->map !== NULL) {
      return $this->map;
    }
    $devMode = $this->isDevMode();
    if (!$devMode && ($cached = $this->cache->get(self::CID))) {
      return $this->map = $cached->data;
    }
    $this->map = $this->build();
    if (!$devMode) {
      $this->cache->set(self::CID, $this->map, Cache::PERMANENT, [self::CACHE_TAG]);
    }
    return $this->map;
  }

  /**
   * Scans every Alchemist component for slot templates.
   *
   * One is_file() per declared slot per component, once per cache rebuild.
   *
   * @return array<string, array<string, string>>
   *   Namespaced Twig paths, keyed by component id then slot name.
   */
  private function build(): array {
    $map = [];
    foreach ($this->componentPluginManager->getDefinitions() as $id => $definition) {
      if (empty($definition['neo']) || empty($definition['path']) || empty($definition['slots'])) {
        continue;
      }
      $namespace = $this->toNamespacePath($definition);
      if ($namespace === NULL) {
        continue;
      }
      foreach (array_keys($definition['slots']) as $slotName) {
        $file = $definition['path'] . '/' . self::DIRECTORY . '/' . $slotName . '.twig';
        if (is_file($file)) {
          $map[$id][$slotName] = $namespace . '/' . self::DIRECTORY . '/' . $slotName . '.twig';
        }
      }
    }
    return $map;
  }

  /**
   * Converts a component definition into its `@namespace/dir` Twig prefix.
   *
   * `$definition['path']` is the absolute component directory and is not
   * realpath()-ed: ComponentPluginManager::getScanDirectories() builds it as
   * `{app root}/{extension path}/components`, and alterDefinition() sets it to
   * `dirname($metadata_path)`. Subtracting the extension prefix is therefore
   * exact string arithmetic.
   *
   * Deliberately does NOT use the metadata object's `path`, which is
   * root-relative with a leading slash — a different value entirely.
   * Discovery is also recursive, so a component may sit at `components/a/b`;
   * nothing here may assume `components/<name>`.
   *
   * @param array $definition
   *   The SDC plugin definition.
   *
   * @return string|null
   *   The prefix, e.g. `@front/components/list_insight`, or NULL when the
   *   provider cannot be resolved.
   *
   * @see \Drupal\Core\Theme\ComponentPluginManager::alterDefinition()
   * @see \Drupal\neo_alchemist\SdcThumbnailWriter::getDirectory()
   */
  private function toNamespacePath(array $definition): ?string {
    $provider = $definition['provider'] ?? '';
    if ($provider === '') {
      return NULL;
    }
    // ExtensionPathResolver holds the module and theme lists in one service, so
    // this is the whole module-versus-theme branch. The definition's
    // 'extension_type' would answer it directly but is an @internal enum.
    $type = $this->moduleHandler->moduleExists($provider) ? 'module' : 'theme';
    try {
      $extensionPath = $this->extensionPathResolver->getPath($type, $provider);
    }
    catch (UnknownExtensionException | UnknownExtensionTypeException) {
      return NULL;
    }
    $prefix = $this->appRoot . '/' . $extensionPath . '/';
    if (!str_starts_with($definition['path'], $prefix)) {
      return NULL;
    }
    return '@' . $provider . '/' . substr($definition['path'], strlen($prefix));
  }

}
