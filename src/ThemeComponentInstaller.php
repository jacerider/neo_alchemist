<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Asset\LibraryDiscoveryCollector;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ExtensionType;
use Drupal\Core\Theme\Registry;

/**
 * Copies module-shipped components into the site's front theme.
 *
 * A module opts a component in by declaring `neo_install: true` in its
 * *.component.yml, alongside `neo: false`. The module's copy stays discoverable
 * — so a module that renders its own component always has a working fallback —
 * but `neo: false` keeps it out of the component picker and the SDC preview
 * list. The copy written into the theme gets `neo: true` and is the one site
 * builders pick, preview and restyle. It is never overwritten once it exists:
 * from the moment it lands it belongs to the site.
 *
 * The ordering this solves is why it lives here rather than in each module.
 * Core installs every module — and runs every hook_install — before any theme
 * during a profile install, so a module cannot reliably eject itself: at
 * hook_install time there is no theme to copy into, and SDC could not discover
 * the copy if there were. Sweeping every installed module again from
 * hook_themes_installed() gets the ordering right once, for everyone.
 */
final class ThemeComponentInstaller {

  /**
   * Constructs the installer.
   */
  public function __construct(
    protected ComponentPluginManager $componentManager,
    protected ThemeHandlerInterface $themeHandler,
    protected ThemeExtensionList $themeExtensionList,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected LibraryDiscoveryInterface $libraryDiscovery,
    protected Registry $themeRegistry,
    protected LoggerChannelInterface $logger,
    protected string $appRoot,
  ) {}

  /**
   * Installs every component that asks for it into a theme.
   *
   * @param string|null $theme
   *   (optional) The target theme; defaults to the site's default theme.
   * @param bool $force
   *   (optional) Overwrite an existing theme copy. Destructive — the copy is
   *   the site's to edit.
   *
   * @return array
   *   Status keyed by component plugin id: 'installed', 'exists' or 'failed'.
   */
  public function installAll(?string $theme = NULL, bool $force = FALSE): array {
    $theme = $this->resolveTheme($theme);
    if (!$theme) {
      return [];
    }
    $results = [];
    foreach (array_keys($this->getInstallableDefinitions()) as $id) {
      $results[$id] = $this->doInstall($id, $theme, $force);
    }
    if (in_array('installed', $results, TRUE)) {
      $this->refreshCaches();
    }
    return $results;
  }

  /**
   * Installs one component into a theme.
   *
   * @return string
   *   'installed', 'exists' or 'failed'.
   *
   * @throws \InvalidArgumentException
   *   When the component does not exist or does not declare neo_install.
   */
  public function install(string $componentId, ?string $theme = NULL, bool $force = FALSE): string {
    $definitions = $this->getInstallableDefinitions();
    if (!isset($definitions[$componentId])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a module component declaring `neo_install: true`. Available: %s.', $componentId, implode(', ', array_keys($definitions)) ?: 'none'));
    }
    $theme = $this->resolveTheme($theme);
    if (!$theme) {
      return 'failed';
    }
    $status = $this->doInstall($componentId, $theme, $force);
    if ($status === 'installed') {
      $this->refreshCaches();
    }
    return $status;
  }

  /**
   * Gets the module components that opt into theme installation.
   *
   * Theme-provided components are skipped even when they declare the key —
   * they are already where a copy would be written.
   *
   * @return array
   *   Component definitions keyed by plugin id.
   */
  public function getInstallableDefinitions(): array {
    return array_filter(
      $this->componentManager->getDefinitions(),
      // extension_type is a backed enum, not a string; casting one throws.
      fn (array $definition): bool => !empty($definition['neo_install'])
        && ($definition['extension_type'] ?? NULL) === ExtensionType::Module
    );
  }

  /**
   * Resolves the target theme, or NULL when there is nothing to install into.
   *
   * A theme that is not installed yet is not an error: on a fresh site modules
   * install first, and hook_themes_installed() runs this again once there is
   * somewhere to copy to.
   */
  public function resolveTheme(?string $theme = NULL): ?string {
    $theme = $theme ?: (string) $this->configFactory->get('system.theme')->get('default');
    if (!$theme || !$this->themeHandler->themeExists($theme)) {
      return NULL;
    }
    return $theme;
  }

  /**
   * Copies one component directory into the theme.
   */
  protected function doInstall(string $componentId, string $theme, bool $force): string {
    $definition = $this->componentManager->getDefinitions()[$componentId] ?? NULL;
    if (!$definition) {
      return 'failed';
    }
    $name = $definition['machineName'];
    $target = $this->appRoot . '/' . $this->themeExtensionList->getPath($theme) . '/components/' . $name;
    if (is_dir($target) && !$force) {
      return 'exists';
    }
    $source = $definition['path'];
    if (!is_dir($source)) {
      $this->logger->warning('Component @id declares neo_install but its directory @path is missing.', [
        '@id' => $componentId,
        '@path' => $source,
      ]);
      return 'failed';
    }
    if (!$this->copyDirectory($source, $target)) {
      $this->logger->warning('Could not write component @id into theme "@theme" at @target.', [
        '@id' => $componentId,
        '@theme' => $theme,
        '@target' => $target,
      ]);
      return 'failed';
    }
    $this->enableCopy($componentId, $target . '/' . $name . '.component.yml');
    return 'installed';
  }

  /**
   * Recursively copies a directory.
   *
   * Recursive rather than a flat glob: a component may ship slots/, images/ or
   * any other subdirectory, and copying a directory as if it were a file fails
   * in a way that is easy to miss.
   */
  protected function copyDirectory(string $source, string $target): bool {
    if (!$this->fileSystem->prepareDirectory($target, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return FALSE;
    }
    foreach (scandir($source) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $from = $source . '/' . $entry;
      $to = $target . '/' . $entry;
      $copied = is_dir($from)
        ? $this->copyDirectory($from, $to)
        : (bool) $this->fileSystem->copy($from, $to, FileExists::Replace);
      if (!$copied) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Turns the copied component into a real Alchemist component.
   *
   * The source declares `neo: false` so it stays out of the picker; the theme
   * copy is the one that should appear, so that single line is flipped. This is
   * a targeted line rewrite rather than a Yaml decode/encode round-trip on
   * purpose — these files carry the comments explaining how the component is
   * meant to be used, and a round-trip would discard every one of them.
   */
  protected function enableCopy(string $componentId, string $file): void {
    if (!is_file($file)) {
      $this->logger->warning('Installed component @id has no @file to enable.', [
        '@id' => $componentId,
        '@file' => basename($file),
      ]);
      return;
    }
    $yaml = (string) file_get_contents($file);
    $enabled = preg_replace('/^neo:[ \t]*false[ \t]*$/m', 'neo: true', $yaml, 1);
    if ($enabled !== NULL && $enabled !== $yaml) {
      file_put_contents($file, $enabled);
      return;
    }
    // Already true is fine. Anything else means the source no longer carries
    // a line to flip, and a theme copy without `neo: true` is invisible in
    // the picker for no discoverable reason, so say so rather than ship it
    // quietly.
    if (!preg_match('/^neo:[ \t]*true[ \t]*$/m', $yaml)) {
      $this->logger->warning('Component @id was installed into the theme but has no `neo: false` line to flip, so the copy will not appear in the component picker. Add `neo: true` to @file.', [
        '@id' => $componentId,
        '@file' => $file,
      ]);
    }
  }

  /**
   * Clears the caches required for a freshly installed component to appear.
   */
  protected function refreshCaches(): void {
    $this->componentManager->clearCachedDefinitions();
    if ($this->libraryDiscovery instanceof LibraryDiscoveryCollector) {
      $this->libraryDiscovery->clear();
    }
    else {
      // @phpstan-ignore method.deprecated
      $this->libraryDiscovery->clearCachedDefinitions();
    }
    $this->themeRegistry->reset();
    Cache::invalidateTags(['rendered']);
  }

}
