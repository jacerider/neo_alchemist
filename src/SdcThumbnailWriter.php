<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_build\NeoBuild;

/**
 * Writes a captured thumbnail into a raw SDC's own directory.
 *
 * Saved neo_component entities store a captured thumbnail as a neo_config_file.
 * A raw SDC has no config entity to hang one on, and the natural home for its
 * thumbnail is the component directory itself — next to the `.component.yml`,
 * where it travels with the component in git and is picked up by core's
 * `thumbnail.png` convention for free.
 *
 * That means writing into the codebase, so the whole feature is gated on the
 * Neo dev server running. This class is the single place that knows the gate,
 * the destination and the validation, so the form (deciding whether to offer
 * the button) and the controller (deciding whether to honor the request) can
 * never disagree.
 *
 * Deliberately emits no cache invalidation. Core resolves component thumbnails
 * in ComponentMetadata::getThumbnailPath(), which is a file_exists() on a path
 * built at call time and memoized per plugin instance only — it is neither in
 * the plugin definition nor in the `component_plugins` cache bin. A thumbnail
 * written here is therefore visible on the very next request with no cache
 * rebuild. Do not "helpfully" add an invalidation later.
 *
 * @see \Drupal\Core\Theme\Component\ComponentMetadata::getThumbnailPath()
 * @see \Drupal\neo_alchemist\Controller\SdcThumbnailCaptureController
 */
final class SdcThumbnailWriter {

  use StringTranslationTrait;

  /**
   * The filename core looks for in a component directory.
   */
  public const FILENAME = 'thumbnail.png';

  /**
   * The largest capture accepted, in bytes.
   */
  public const MAX_BYTES = 4194304;

  /**
   * The PNG signature every valid PNG starts with.
   */
  private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

  /**
   * Accepted pixel dimensions, guarding against junk and against 1px probes.
   */
  private const MIN_WIDTH = 200;
  private const MAX_WIDTH = 4000;
  private const MIN_HEIGHT = 100;
  private const MAX_HEIGHT = 6000;

  /**
   * The config split whose being active marks a local development checkout.
   */
  private const DEV_SPLIT = 'config_split.config_split.dev';

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ?NeoBuild $neoBuild,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $appRoot,
  ) {}

  /**
   * Whether writing thumbnails into component directories is offered at all.
   *
   * Either signal is enough, and both mean the same thing: this is somebody's
   * working checkout rather than a deployed site.
   *
   * - The Neo dev server is running. Narrow — it only holds while `npm start`
   *   is up — but it carries the useful corollary that the feature is
   *   available exactly when this module's TypeScript is being compiled live.
   * - The dev config split is enabled, which is switched on from the
   *   (gitignored) settings.local.php and so stays true for a whole local
   *   environment regardless of whether a dev server happens to be running.
   *
   * Both dependencies are soft. neo_build is not a declared dependency of
   * neo_alchemist, so a Kernel test's minimal module list may not provide it;
   * config_split need not be installed at all, in which case the config object
   * is empty and its status reads as nothing. Either way the answer is "not a
   * development environment", which is the safe default.
   *
   * @return bool
   *   TRUE when the feature is available.
   */
  public function isEnabled(): bool {
    if ($this->neoBuild?->isDevMode()) {
      return TRUE;
    }
    // Read through the config factory rather than the entity, so the override
    // in settings.local.php is what counts — the stored entity says FALSE.
    return (bool) $this->configFactory->get(self::DEV_SPLIT)->get('status');
  }

  /**
   * Returns the absolute directory of an Alchemist SDC.
   *
   * Uses the plugin definition's `path`, which core sets to the absolute
   * component directory. Note that the metadata object's `path` is a different
   * value — root-relative — and must never be used to build a write target.
   *
   * @param string $component
   *   The SDC component id (e.g. "front:hero_s1").
   *
   * @return string|null
   *   The absolute directory, or NULL for an unknown or non-Alchemist SDC.
   *
   * @see \Drupal\Core\Theme\ComponentPluginManager::alterDefinition()
   */
  public function getDirectory(string $component): ?string {
    $definition = $this->componentPluginManager->hasDefinition($component)
      ? $this->componentPluginManager->getDefinition($component)
      : NULL;
    if (!$definition || empty($definition['neo']) || empty($definition['path'])) {
      return NULL;
    }
    return $definition['path'];
  }

  /**
   * Returns the root-relative path of an existing thumbnail, if there is one.
   *
   * @param string $component
   *   The SDC component id.
   *
   * @return string|null
   *   The root-relative path, or NULL when the component has no thumbnail.
   */
  public function getExistingPath(string $component): ?string {
    $directory = $this->getDirectory($component);
    if (!$directory || !is_file($directory . '/' . self::FILENAME)) {
      return NULL;
    }
    return $this->toRelative($directory) . '/' . self::FILENAME;
  }

  /**
   * Whether a capture would succeed right now.
   *
   * @param string $component
   *   The SDC component id.
   *
   * @return bool
   *   TRUE when the thumbnail can be written.
   */
  public function isWritable(string $component): bool {
    return $this->getUnavailableReason($component) === NULL;
  }

  /**
   * Returns why a capture cannot be written, or NULL when it can.
   *
   * The sentence is shown to the developer verbatim — in the button's tooltip
   * and in the endpoint's JSON error — so it names the directory and the fix.
   *
   * @param string $component
   *   The SDC component id.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason, or NULL when writable.
   */
  public function getUnavailableReason(string $component): ?TranslatableMarkup {
    if (!$this->isEnabled()) {
      return $this->t('Thumbnails can only be captured during local development — with the Neo dev server running, or the dev config split enabled.');
    }
    $directory = $this->getDirectory($component);
    if (!$directory || !is_dir($directory)) {
      return $this->t('The component directory could not be found.');
    }
    if (!is_writable($directory)) {
      return $this->t('The directory @dir is not writable by the web server.', [
        '@dir' => $this->toRelative($directory),
      ]);
    }
    // A read-only thumbnail.png inside a writable directory is the common
    // half-broken state, and is_writable() on the directory does not catch it.
    $target = $directory . '/' . self::FILENAME;
    if (file_exists($target) && !is_writable($target)) {
      return $this->t('The existing file @file is not writable by the web server.', [
        '@file' => $this->toRelative($directory) . '/' . self::FILENAME,
      ]);
    }
    return NULL;
  }

  /**
   * Validates a captured PNG and writes it into the component directory.
   *
   * @param string $component
   *   The SDC component id.
   * @param string $bytes
   *   The raw PNG bytes.
   *
   * @return array
   *   An array with 'path' (root-relative), 'url' (cache-busted) and 'bytes'.
   *
   * @throws \InvalidArgumentException
   *   When the component or the payload is unusable. The exception code is the
   *   HTTP status the endpoint should return.
   * @throws \RuntimeException
   *   When writing is not permitted or fails. The exception code is the HTTP
   *   status the endpoint should return.
   */
  public function write(string $component, string $bytes): array {
    $directory = $this->getDirectory($component);
    if (!$directory) {
      throw new \InvalidArgumentException('Unknown component.', 404);
    }
    // Fail on the gate before parsing anything: it is both the cheapest check
    // and by far the most likely reason for a request to be refused.
    if ($reason = $this->getUnavailableReason($component)) {
      throw new \RuntimeException((string) $reason, 403);
    }
    if ($bytes === '') {
      throw new \InvalidArgumentException('The request body was empty.', 400);
    }
    if (strlen($bytes) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('The captured image is too large.', 413);
    }
    if (!str_starts_with($bytes, self::PNG_MAGIC)) {
      throw new \InvalidArgumentException('The captured image is not a PNG.', 400);
    }
    // Stronger than sniffing the MIME type, which trusts the magic bytes
    // alone: this reads the IHDR chunk. It is lenient about what it finds
    // there, which is why the dimension check below is not decoration — junk
    // after a valid signature reliably yields nonsense dimensions. Lives in
    // ext/standard, so it costs no GD dependency.
    $size = getimagesizefromstring($bytes);
    if ($size === FALSE || $size[2] !== IMAGETYPE_PNG) {
      throw new \InvalidArgumentException('The captured image could not be read as a PNG.', 400);
    }
    if ($size[0] < self::MIN_WIDTH || $size[0] > self::MAX_WIDTH
      || $size[1] < self::MIN_HEIGHT || $size[1] > self::MAX_HEIGHT) {
      throw new \InvalidArgumentException('The captured image has unusable dimensions.', 400);
    }

    // The component id is only ever a key looked up in the plugin definitions,
    // so traversal is already structurally impossible. Kept as belt-and-braces
    // against a hostile or symlinked extension directory.
    $realDirectory = realpath($directory);
    $realRoot = realpath($this->appRoot);
    if ($realDirectory === FALSE || $realRoot === FALSE
      || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)) {
      throw new \RuntimeException('The component directory is outside the site root.', 403);
    }

    $target = $realDirectory . '/' . self::FILENAME;
    // Refuse to follow a pre-planted symlink at the destination.
    if (file_exists($target) && realpath($target) !== $target) {
      throw new \RuntimeException('The existing thumbnail is a symbolic link.', 403);
    }

    // The web server serves this exact path while it is being written, so go
    // through a temporary file and rename onto the target — an intra-directory
    // rename is atomic, and a reader never sees a truncated PNG.
    $temporary = $realDirectory . '/.' . self::FILENAME . '.' . uniqid() . '.tmp';
    if (file_put_contents($temporary, $bytes) !== strlen($bytes)) {
      @unlink($temporary);
      throw new \RuntimeException('The thumbnail could not be written.', 500);
    }
    if (!rename($temporary, $target)) {
      @unlink($temporary);
      throw new \RuntimeException('The thumbnail could not be moved into place.', 500);
    }
    @chmod($target, 0664);
    clearstatcache(TRUE, $target);

    $relative = $this->toRelative($realDirectory) . '/' . self::FILENAME;
    return [
      'path' => $relative,
      'url' => '/' . $relative . '?v=' . filemtime($target),
      'bytes' => strlen($bytes),
    ];
  }

  /**
   * Converts an absolute path to a root-relative one.
   *
   * @param string $path
   *   The absolute path.
   *
   * @return string
   *   The path relative to the Drupal root, without a leading slash.
   */
  private function toRelative(string $path): string {
    $root = rtrim($this->appRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $root)) {
      return ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
    }
    return ltrim($path, DIRECTORY_SEPARATOR);
  }

}
