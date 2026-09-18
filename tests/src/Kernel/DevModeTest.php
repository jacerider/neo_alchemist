<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dev mode is off unless something says otherwise.
 *
 * Both of its signals are deliberately soft. neo_build is not a declared
 * dependency of neo_alchemist, so the service is wired with '@?neo_build' and
 * resolves to NULL under a minimal module list — which is exactly the situation
 * every Kernel test runs in, and exactly what a hard reference would turn into
 * a container compilation failure. config_split need not be installed either,
 * in which case its config object is empty and reads as nothing.
 *
 * The answer that matters is the one given when neither signal is present,
 * because that is a deployed site: developer-facing features must be off. This
 * pins that default, and pins that the container still builds without
 * neo_build — the reason the two callers below share one service rather than
 * carrying a copy each.
 *
 * @see \Drupal\neo_alchemist\DevMode::isDevMode()
 * @see \Drupal\neo_alchemist\SdcThumbnailWriter::isEnabled()
 * @see \Drupal\neo_alchemist\Slot\ComponentSlotTemplateLocator::isDevMode()
 */
#[Group('neo_alchemist')]
class DevModeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * {@inheritdoc}
   *
   * The split's config object belongs to config_split, which is not installed
   * here — that absence is the whole point, since it is how a site without the
   * module behaves. Writing the object anyway is the only way to exercise the
   * signal, and its schema necessarily comes with the module that owns it.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Neither signal present means this is not a development environment.
   */
  public function testDefaultsToOffWithoutEitherSignal(): void {
    $this->assertFalse($this->container->get('neo_alchemist.dev_mode')->isDevMode());
  }

  /**
   * The dev config split being on is enough on its own.
   *
   * Read through the config factory rather than the entity, so an override in
   * settings.local.php is what counts — which is how a developer's checkout
   * reports itself when no dev server is running.
   */
  public function testConfigSplitStatusAloneEnablesIt(): void {
    $this->config('config_split.config_split.dev')->set('status', TRUE)->save();
    $this->assertTrue($this->container->get('neo_alchemist.dev_mode')->isDevMode());
  }

  /**
   * Both callers report the same answer, because they share one implementation.
   */
  public function testCallersAgreeWithTheService(): void {
    $devMode = $this->container->get('neo_alchemist.dev_mode');
    $this->assertSame(
      $devMode->isDevMode(),
      $this->container->get('neo_alchemist.sdc_thumbnail_writer')->isEnabled(),
    );
    $this->assertSame(
      $devMode->isDevMode(),
      $this->container->get('neo_alchemist.slot_template_locator')->isDevMode(),
    );
  }

}
