<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\neo_build\NeoBuild;

/**
 * Answers whether this is somebody's working checkout.
 *
 * Two features asked the same question and answered it with the same fifteen
 * lines — SdcThumbnailWriter, which offers to write a thumbnail into a
 * component directory, and ComponentSlotTemplateLocator, which annotates slots
 * with the template that would control them. A third caller made the copy worth
 * retiring rather than repeating, so both now delegate here.
 */
final class DevMode {

  /**
   * The config split whose being active marks a local development checkout.
   */
  private const DEV_SPLIT = 'config_split.config_split.dev';

  public function __construct(
    private readonly ?NeoBuild $neoBuild,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether developer-facing features are offered at all.
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
   *   TRUE when this is a development environment.
   */
  public function isDevMode(): bool {
    if ($this->neoBuild?->isDevMode()) {
      return TRUE;
    }
    // Read through the config factory rather than the entity, so the override
    // in settings.local.php is what counts — the stored entity says FALSE.
    return (bool) $this->configFactory->get(self::DEV_SPLIT)->get('status');
  }

}
