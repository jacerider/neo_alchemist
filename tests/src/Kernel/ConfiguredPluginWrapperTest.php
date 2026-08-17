<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Changing a wrapper's plugin id or settings drops the built instance.
 *
 * `ComponentAccess` and `ComponentFilter` memoise the plugin they build, so an
 * instance carrying the outgoing settings must not survive a write to either
 * input. That rule used to be written out twice, once per class, and moving it
 * into ConfiguredPluginWrapperTrait is only safe if the rule itself is pinned.
 *
 * It was not. Both wrappers set their properties directly in the constructor
 * rather than through the setters, so `setPluginId()` and `setPluginSettings()`
 * are reached only from the access and filter admin forms — no test in the
 * suite called either one, and none observed the memo being dropped. The
 * refactor could have inverted this logic and stayed green.
 *
 * Rebuilds are detected by object identity against the real plugin manager,
 * which needs no test double — and the manager is final, so it could not be
 * doubled anyway. ComponentFilter stands in for both wrappers; the trait
 * supplies the one implementation.
 *
 * @see \Drupal\neo_alchemist\ConfiguredPluginWrapperTrait
 */
#[Group('neo_alchemist')]
class ConfiguredPluginWrapperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_alchemist']);
  }

  /**
   * Builds a filter wrapper around the given plugin id and settings.
   */
  private function filter(string $pluginId = 'string', array $pluginSettings = [], bool $withUuid = TRUE): object {
    $component = Component::create([
      'label' => 'Wrapper fixture',
      'description' => 'Wrapper fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
    ]);
    $settings = [
      'plugin_id' => $pluginId,
      'plugin_settings' => $pluginSettings,
    ];
    if ($withUuid) {
      $settings['uuid'] = 'a-uuid';
    }
    return $this->container->get('neo_component.filter.factory')->get($component, $settings);
  }

  /**
   * The instance is built once and reused.
   */
  public function testPluginIsMemoised(): void {
    $filter = $this->filter();

    $first = $filter->getPlugin();

    $this->assertNotNull($first);
    $this->assertSame($first, $filter->getPlugin(), 'A second read must not rebuild.');
  }

  /**
   * Writing different settings drops the instance built from the old ones.
   */
  public function testChangingSettingsDropsTheInstance(): void {
    $filter = $this->filter('string', ['mode' => 'one']);
    $first = $filter->getPlugin();

    $filter->setPluginSettings(['mode' => 'two']);

    $this->assertSame(['mode' => 'two'], $filter->getPluginSettings());
    $this->assertNotSame($first, $filter->getPlugin(), 'The memo held settings that no longer apply.');
  }

  /**
   * Writing the same settings keeps the instance, since nothing changed.
   */
  public function testRewritingIdenticalSettingsKeepsTheInstance(): void {
    $filter = $this->filter('string', ['mode' => 'one']);
    $first = $filter->getPlugin();

    $filter->setPluginSettings(['mode' => 'one']);

    $this->assertSame($first, $filter->getPlugin(), 'An identical write is not a change.');
  }

  /**
   * Writing a different plugin id drops both the instance and the settings.
   *
   * The outgoing plugin's settings cannot configure a different plugin, so
   * they go with it rather than being handed to the new one.
   */
  public function testChangingPluginIdAlsoClearsSettings(): void {
    $filter = $this->filter('string', ['mode' => 'one']);
    $first = $filter->getPlugin();

    $filter->setPluginId('number');

    $this->assertSame('number', $filter->getPluginId());
    $this->assertSame([], $filter->getPluginSettings(), 'Settings belonged to the outgoing plugin.');
    $this->assertNotSame($first, $filter->getPlugin());
  }

  /**
   * Rewriting the same plugin id changes nothing, settings included.
   */
  public function testRewritingTheSamePluginIdKeepsSettings(): void {
    $filter = $this->filter('string', ['mode' => 'one']);
    $first = $filter->getPlugin();

    $filter->setPluginId('string');

    $this->assertSame(['mode' => 'one'], $filter->getPluginSettings());
    $this->assertSame($first, $filter->getPlugin());
  }

  /**
   * A wrapper with no uuid is new; one restored from settings is not.
   */
  public function testIsNewTracksTheUuid(): void {
    $this->assertFalse($this->filter()->isNew());
    $this->assertTrue($this->filter('string', [], FALSE)->isNew());
  }

  /**
   * An id the manager does not know resolves to no plugin.
   */
  public function testUnknownPluginIdResolvesToNull(): void {
    $this->assertNull($this->filter('no_such_filter_plugin')->getPlugin());
  }

}
