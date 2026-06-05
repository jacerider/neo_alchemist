<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Registry;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\Registry;
use Drupal\neo_alchemist\ComponentPropDefPluginManager;

/**
 * Installs registry components into a theme's components directory.
 */
final class ComponentInstaller {

  /**
   * The Twig namespace registry components are authored against.
   *
   * Components reference shared partials as "@front/includes/...". When a
   * component is installed into a theme with a different machine name the
   * namespace is rewritten to "@<theme>/...".
   */
  const REGISTRY_NAMESPACE = 'front';

  /**
   * The provider (module) that owns transient preview components.
   *
   * Previews are materialized as components of this submodule so nothing is
   * written into the user's theme. The "@front/" twig namespace is rewritten to
   * this provider's namespace so shared partials resolve under the module.
   */
  const PREVIEW_PROVIDER = 'neo_alchemist_library';

  /**
   * Constructs the ComponentInstaller.
   */
  public function __construct(
    protected readonly RegistryClient $registryClient,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ThemeExtensionList $themeExtensionList,
    protected readonly ThemeHandlerInterface $themeHandler,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ComponentPluginManager $pluginManagerSdc,
    protected readonly LibraryDiscoveryInterface $libraryDiscovery,
    protected readonly Registry $themeRegistry,
    protected readonly ComponentPropDefPluginManager $propDefManager,
    protected readonly ModuleExtensionList $moduleExtensionList,
    protected readonly string $appRoot,
  ) {}

  /**
   * Installs a component (and its dependencies) into a theme.
   *
   * @param string $name
   *   The registry component machine name to install.
   * @param string|null $target
   *   New machine name for the installed component (defaults to $name). Only
   *   the requested component is renamed; dependencies keep their own names.
   * @param string|null $theme
   *   Target theme machine name (defaults to the active front theme).
   * @param bool $force
   *   Overwrite an existing component folder / shared partials.
   *
   * @return \Drupal\neo_alchemist_library\Registry\InstallResult
   *   The outcome (written, skipped, warnings, installed names).
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   On invalid name, unknown theme, or registry/network failure.
   */
  public function install(string $name, ?string $target = NULL, ?string $theme = NULL, bool $force = FALSE): InstallResult {
    $result = new InstallResult();
    $targetName = $target ?: $name;
    $this->validateMachineName($targetName);

    $themeName = $this->resolveFrontTheme($theme);
    $result->theme = $themeName;
    $result->targetName = $targetName;

    $themeRel = $this->themeExtensionList->getPath($themeName);
    $themeAbs = $this->appRoot . '/' . $themeRel;

    // Warn (do not block) when the target theme is not a Neo theme.
    $info = $this->themeExtensionList->get($themeName)->info ?? [];
    if (empty($info['neo'])) {
      $result->addWarning(sprintf("Theme '%s' is not a Neo theme; components rely on Neo Twig functions and the Alpine library that this theme may not provide.", $themeName));
    }

    // Resolve the dependency graph (dependencies first, deduped, cycle-safe).
    $ordered = [];
    $seen = [];
    $this->resolveDependencies($name, $seen, $ordered);

    foreach ($ordered as $itemName => $item) {
      // Only the explicitly requested component is renamed.
      $itemTarget = $itemName === $name ? $targetName : $itemName;
      $this->writeItem($item, $itemTarget, $themeName, $themeAbs, $themeRel, $force, $result);
    }

    if ($result->hasChanges()) {
      $this->refreshCaches();
    }

    return $result;
  }

  /**
   * Resolves the target theme machine name.
   *
   * Priority: explicit argument, configured default, then the site's default
   * theme (system.theme:default).
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   When no installed theme can be resolved.
   */
  public function resolveFrontTheme(?string $theme = NULL): string {
    $name = $theme;
    if (!$name) {
      $name = $this->configFactory->get('neo_alchemist_library.settings')->get('default_theme');
    }
    if (!$name) {
      $name = $this->configFactory->get('system.theme')->get('default');
    }
    if (!$name) {
      throw new RegistryException('Could not determine a target theme. Pass --theme or set a default in registry settings.');
    }
    if (!$this->themeHandler->themeExists($name)) {
      throw new RegistryException(sprintf("Theme '%s' is not installed.", $name));
    }
    return $name;
  }

  /**
   * Materializes a registry component as a transient submodule-owned SDC.
   *
   * Writes the bundle to this submodule's components/ (and templates/ for any
   * shared partials, with the "@front/" namespace rewritten to the module's),
   * so nothing touches the user's theme, then registers it with SDC. Call
   * cleanupPreview() with the returned handle when done.
   *
   * @param string $name
   *   The registry component machine name to preview.
   *
   * @return array{componentId: string, previewId: string, createdPaths: string[]}
   *   A handle for rendering and cleanup.
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   When the component cannot be fetched.
   */
  public function materializePreview(string $name): array {
    $item = $this->registryClient->getItem($name);
    $previewId = 'preview_' . substr(md5($name . uniqid('', TRUE)), 0, 10);
    $moduleAbs = $this->appRoot . '/' . $this->moduleExtensionList->getPath(self::PREVIEW_PROVIDER);
    $componentDir = $moduleAbs . '/components/' . $previewId;
    $created = [];

    foreach ($item->files as $file) {
      if ($file->isComponentFile()) {
        $dir = dirname($file->path);
        $newBase = $this->renameBasename(basename($file->path), $item->name, $previewId);
        $relInComp = $dir === '.' ? $newBase : $dir . '/' . $newBase;
        $abs = $componentDir . '/' . $relInComp;
        // Rewrite "@front/..." to the module namespace so partials resolve here.
        $content = $this->prepareContent($file, $relInComp, self::PREVIEW_PROVIDER);
        $this->rawWrite($abs, $content);
      }
      else {
        $abs = $moduleAbs . '/templates/' . $file->path;
        $content = $this->prepareContent($file, $file->path, self::PREVIEW_PROVIDER);
        $this->rawWrite($abs, $content);
        // Track partials for individual cleanup (don't delete the whole dir).
        $created[] = $abs;
      }
    }
    $created[] = $componentDir;

    // Make the transient component discoverable to SDC.
    $this->pluginManagerSdc->clearCachedDefinitions();

    return [
      'componentId' => self::PREVIEW_PROVIDER . ':' . $previewId,
      'previewId' => $previewId,
      'createdPaths' => $created,
    ];
  }

  /**
   * Removes a materialized preview and refreshes SDC definitions.
   *
   * @param array{createdPaths?: string[]} $handle
   *   The handle returned by materializePreview().
   */
  public function cleanupPreview(array $handle): void {
    foreach ($handle['createdPaths'] ?? [] as $path) {
      if (is_dir($path)) {
        $this->fileSystem->deleteRecursive($path);
      }
      elseif (is_file($path)) {
        $this->fileSystem->delete($path);
      }
    }
    $this->pluginManagerSdc->clearCachedDefinitions();
  }

  /**
   * Writes content to an absolute path, creating directories as needed.
   */
  protected function rawWrite(string $abs, string $content): void {
    $dir = dirname($abs);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->saveData($content, $abs, FileExists::Replace);
  }

  /**
   * Depth-first dependency resolution producing a deps-first ordered map.
   *
   * @param string $name
   *   Component name to resolve.
   * @param array<string, bool> $seen
   *   Names already visited (by reference).
   * @param array<string, \Drupal\neo_alchemist_library\Registry\RegistryItem> $ordered
   *   Resolved items keyed by name, dependencies before dependents (by ref).
   */
  protected function resolveDependencies(string $name, array &$seen, array &$ordered): void {
    if (isset($seen[$name])) {
      return;
    }
    $seen[$name] = TRUE;
    $item = $this->registryClient->getItem($name);
    foreach ($item->registryDependencies as $dep) {
      $this->resolveDependencies($dep, $seen, $ordered);
    }
    $ordered[$name] = $item;
  }

  /**
   * Writes a single resolved component item to disk.
   */
  protected function writeItem(RegistryItem $item, string $targetName, string $themeName, string $themeAbs, string $themeRel, bool $force, InstallResult $result): void {
    $componentDir = $themeAbs . '/components/' . $targetName;
    $componentRel = $themeRel . '/components/' . $targetName;

    if (is_dir($componentDir) && !$force) {
      $result->addWarning(sprintf("Component '%s' already exists at %s — skipped (use --force to overwrite).", $targetName, $componentRel));
      $result->addSkipped($componentRel);
      return;
    }

    $result->addInstalled($targetName);

    // Inform the user if the component references prop types that are not
    // defined on this site (e.g. a custom prop from the source project). The
    // component still installs and renders — neo_alchemist falls back to plain
    // text for unknown types — but the editor loses those fields' widgets.
    $undefined = $this->findUndefinedPropTypes($item);
    if ($undefined) {
      $result->addWarning(sprintf(
        "Component '%s' uses prop type(s) not defined on this site: %s. These fields fall back to plain text until a module or theme provides the definition.",
        $targetName,
        implode(', ', $undefined),
      ));
    }

    foreach ($item->files as $file) {
      if ($file->isComponentFile()) {
        $dir = dirname($file->path);
        $newBase = $this->renameBasename(basename($file->path), $item->name, $targetName);
        $relInComp = $dir === '.' ? $newBase : $dir . '/' . $newBase;
        $abs = $componentDir . '/' . $relInComp;
        $rel = $componentRel . '/' . $relInComp;
        $content = $this->prepareContent($file, $relInComp, $themeName);
        // The component folder gate above already enforced new-or-force.
        $this->writeFile($abs, $rel, $content, TRUE, $result);
      }
      else {
        // Shared theme partials: written verbatim, never renamed, and not
        // clobbered unless --force (they may be customised / shared).
        $abs = $themeAbs . '/templates/' . $file->path;
        $rel = $themeRel . '/templates/' . $file->path;
        $content = $this->prepareContent($file, $file->path, $themeName);
        if (is_file($abs) && !$force) {
          $result->addSkipped($rel . ' (kept existing)');
          continue;
        }
        $this->writeFile($abs, $rel, $content, TRUE, $result);
      }
    }
  }

  /**
   * Finds prop types in a component bundle that aren't defined on this site.
   *
   * @return string[]
   *   Unique type names that are neither a registered prop def, a JSON-schema
   *   primitive, nor a real class/interface.
   */
  public function findUndefinedPropTypes(RegistryItem $item): array {
    $yaml = NULL;
    foreach ($item->files as $file) {
      if ($file->isComponentFile() && $file->isText() && str_ends_with($file->path, '.component.yml')) {
        $yaml = $file->content;
        break;
      }
    }
    if ($yaml === NULL) {
      return [];
    }
    try {
      $data = Yaml::decode($yaml);
    }
    catch (\Throwable) {
      return [];
    }
    $properties = $data['props']['properties'] ?? [];
    if (!is_array($properties)) {
      return [];
    }
    $known = $this->propDefManager->getDefinitions();
    $unknown = [];
    foreach ($properties as $prop) {
      $this->collectUnknownTypes($prop, $known, $unknown);
    }
    return array_values(array_unique($unknown));
  }

  /**
   * Recursively collects unknown prop types from a schema node.
   *
   * @param mixed $node
   *   A prop schema node (may contain nested properties / items).
   * @param array $known
   *   Registered prop-def definitions keyed by type name.
   * @param string[] $unknown
   *   Collected unknown type names (by reference).
   */
  protected function collectUnknownTypes($node, array $known, array &$unknown): void {
    if (!is_array($node)) {
      return;
    }
    $primitives = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];
    if (isset($node['type'])) {
      $types = is_array($node['type']) ? $node['type'] : [$node['type']];
      foreach ($types as $type) {
        if (is_string($type) && $type !== ''
          && !in_array($type, $primitives, TRUE)
          && !isset($known[$type])
          && !class_exists($type)
          && !interface_exists($type)
        ) {
          $unknown[] = $type;
        }
      }
    }
    if (!empty($node['properties']) && is_array($node['properties'])) {
      foreach ($node['properties'] as $child) {
        $this->collectUnknownTypes($child, $known, $unknown);
      }
    }
    if (!empty($node['items'])) {
      $this->collectUnknownTypes($node['items'], $known, $unknown);
    }
  }

  /**
   * Renames a basename "<source>.ext" -> "<target>.ext".
   */
  protected function renameBasename(string $basename, string $source, string $target): string {
    if (str_starts_with($basename, $source . '.')) {
      return $target . substr($basename, strlen($source));
    }
    return $basename;
  }

  /**
   * Prepares file content: decode binaries, rewrite twig namespaces.
   */
  protected function prepareContent(RegistryFile $file, string $effectivePath, string $themeName): string {
    if (!$file->isText()) {
      return $file->getDecodedContent();
    }
    $content = $file->content;
    if (str_ends_with($effectivePath, '.twig')) {
      $content = $this->rewriteNamespace($content, $themeName);
    }
    return $content;
  }

  /**
   * Rewrites the "@front/" Twig namespace to the target theme's namespace.
   */
  protected function rewriteNamespace(string $content, string $themeName): string {
    if ($themeName === self::REGISTRY_NAMESPACE) {
      return $content;
    }
    return str_replace('@' . self::REGISTRY_NAMESPACE . '/', '@' . $themeName . '/', $content);
  }

  /**
   * Prepares the destination directory and writes the file.
   */
  protected function writeFile(string $abs, string $rel, string $content, bool $force, InstallResult $result): void {
    $dir = dirname($abs);
    if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $result->addWarning(sprintf('Could not prepare directory: %s', $dir));
      return;
    }
    if (is_file($abs) && !$force) {
      $result->addSkipped($rel);
      return;
    }
    try {
      $this->fileSystem->saveData($content, $abs, FileExists::Replace);
      $result->addWritten($rel);
    }
    catch (\Throwable $e) {
      $result->addWarning(sprintf('Failed to write %s: %s', $rel, $e->getMessage()));
    }
  }

  /**
   * Validates a component machine name.
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   */
  protected function validateMachineName(string $name): void {
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
      throw new RegistryException(sprintf("Invalid component name '%s'. Use lowercase letters, numbers and underscores, starting with a letter.", $name));
    }
  }

  /**
   * Clears the caches required for a freshly installed component to appear.
   */
  protected function refreshCaches(): void {
    $this->pluginManagerSdc->clearCachedDefinitions();
    $this->libraryDiscovery->clearCachedDefinitions();
    $this->themeRegistry->reset();
    Cache::invalidateTags(['rendered']);
  }

}
