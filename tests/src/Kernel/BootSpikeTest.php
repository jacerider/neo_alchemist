<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\ComponentPluginManager;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the module can boot under KernelTestBase at all.
 *
 * The Neo dependency graph is mutually entangled around `neo`, so the real
 * question this answers is how few modules a Kernel test can get away with.
 * KernelTestBase::enableModules() does NOT resolve declared dependencies — it
 * enables exactly what is listed — so the list below is deliberately far
 * smaller than neo_alchemist.info.yml's `dependencies` key.
 */
#[Group('neo_alchemist')]
class BootSpikeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo_settings` is not optional: neo_alchemist.services.yml declares
   * `neo_alchemist.settings` with `parent: neo_settings.repository`, and only
   * neo_settings provides that service. `neo` itself ships no services file,
   * so it is deliberately absent until something proves it is needed.
   *
   * `field` is deliberately absent too, which is easy to get wrong: the shape
   * system builds field items for every prop, but `plugin.manager.field.*` are
   * declared in core.services.yml rather than by the `field` module. The whole
   * Kernel suite was verified to pass without it. Add `field` only when a test
   * creates real FieldStorageConfig/FieldConfig entities.
   *
   * The hard floor is narrower still — `neo_settings` + `neo_alchemist` alone
   * boots and resolves values. `system` and `user` are kept as the conventional
   * Drupal baseline: they cost nothing measurable and anything touching entity
   * access or site config will want them.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * The container builds and the module's plugin managers are reachable.
   */
  public function testContainerBoots(): void {
    $this->assertInstanceOf(
      ComponentPluginManager::class,
      $this->container->get('plugin.manager.sdc'),
    );
    $this->assertNotNull($this->container->get('plugin.manager.neo_component_shape'));
    $this->assertNotNull($this->container->get('plugin.manager.neo_component_value'));
    $this->assertNotNull($this->container->get('neo_alchemist.preview_builder'));
  }

  /**
   * Plugin discovery runs without exploding.
   *
   * ComponentPluginManager::setCachedDefinitions() does a `neo_component`
   * config-entity round trip on every definition warm, so this is a more
   * demanding assertion than it looks.
   */
  public function testPluginDiscovery(): void {
    $this->assertIsArray($this->container->get('plugin.manager.sdc')->getDefinitions());
    $shapes = $this->container->get('plugin.manager.neo_component_shape')->getDefinitions();
    $this->assertIsArray($shapes);
    // A few shapes that the value pipeline tests will depend on.
    $this->assertArrayHasKey('string', $shapes);
    $this->assertArrayHasKey('array', $shapes);
    $this->assertArrayHasKey('object', $shapes);
  }

}
