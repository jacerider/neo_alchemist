<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * What neo_alchemist_update_11007() writes onto stored read_time providers.
 *
 * `read_time` gained a processing mode. Its default is stop_when_found, and
 * because it always produces a non-empty placeholder that means it now claims —
 * so a later provider on the same prop can no longer overwrite it. The hook
 * writes that mode into stored config so the behaviour change is visible in a
 * `git diff` rather than living silently in the plugin default.
 *
 * The rules that matter are the ones that make it safe to run unattended
 * against several production sites: it touches only read_time, only where the
 * key is absent, and it is idempotent.
 *
 * Raw config rather than the entity API on purpose: Component::save() writes a
 * plugin's FULL configuration, so an absent `processing_mode` — the state the
 * sites are actually in — cannot be produced through it.
 *
 * @see neo_alchemist_update_11007()
 */
#[Group('neo_alchemist')]
class ReadTimeModeBackfillTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The fixture's config object name.
   */
  private const NAME = 'neo_alchemist.neo_component.na_pmode';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Component::create([
      'id' => 'na_pmode',
      'label' => 'Read time fixture',
      'description' => 'Read time fixture',
      'component' => 'neo_alchemist_test:na_leaf',
      'status' => TRUE,
    ])->save();
    $this->container->get('module_handler')->loadInclude('neo_alchemist', 'install');
  }

  /**
   * Writes a plugin layout onto the fixture, runs the update, reads it back.
   *
   * @param array $shapes
   *   Plugin instances keyed by shape id, so one call can lay out both the
   *   prop's own shape (`text`) and a nested child (`text~child`).
   *
   * @return array
   *   The fixture's `settings.props.text.plugins` after the update ran.
   */
  private function migrate(array $shapes): array {
    $config = $this->container->get('config.factory')->getEditable(self::NAME);
    $data = $config->getRawData();
    $data['settings']['props'] = [
      'text' => [
        'prop' => 'text',
        'ref' => 'string',
        'active' => TRUE,
        'editable' => TRUE,
        'plugins' => $shapes,
      ],
    ];
    $config->setData($data)->save();

    neo_alchemist_update_11007();

    return $this->container->get('config.factory')
      ->getEditable(self::NAME)
      ->getRawData()['settings']['props']['text']['plugins'];
  }

  /**
   * Reads a stored mode back, or NULL when the key was never written.
   */
  private static function mode(array $plugins, string $shapeId, string $pluginId): ?string {
    return $plugins[$shapeId][$pluginId]['settings']['processing_mode'] ?? NULL;
  }

  /**
   * A read_time provider with no stored mode gains stop_when_found.
   */
  public function testModelessReadTimeGainsStopWhenFound(): void {
    $plugins = $this->migrate([
      'text' => [
        'read_time' => ['id' => 'read_time', 'settings' => ['words_per_minute' => 200]],
      ],
    ]);

    $this->assertSame('stop_when_found', self::mode($plugins, 'text', 'read_time'));
    // The rest of the instance's settings are left intact.
    $this->assertSame(200, $plugins['text']['read_time']['settings']['words_per_minute']);
  }

  /**
   * A read_time on an expanded child shape id is reached too.
   */
  public function testNestedShapeIdReadTimeIsReached(): void {
    $plugins = $this->migrate([
      'text~child' => [
        'read_time' => ['id' => 'read_time', 'settings' => []],
      ],
    ]);

    $this->assertSame('stop_when_found', self::mode($plugins, 'text~child', 'read_time'));
  }

  /**
   * A mode a site builder set by hand is never overwritten.
   */
  public function testExplicitModeIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => [
        'read_time' => ['id' => 'read_time', 'settings' => ['processing_mode' => 'continue']],
      ],
    ]);

    $this->assertSame('continue', self::mode($plugins, 'text', 'read_time'));
  }

  /**
   * Only read_time is touched; a co-located provider is left alone.
   */
  public function testOtherProvidersAreUntouched(): void {
    $plugins = $this->migrate([
      'text' => [
        'page_title' => ['id' => 'page_title', 'settings' => []],
        'read_time' => ['id' => 'read_time', 'settings' => []],
      ],
    ]);

    $this->assertNull(self::mode($plugins, 'text', 'page_title'), 'The hook writes nothing onto a plugin that is not read_time.');
    $this->assertSame('stop_when_found', self::mode($plugins, 'text', 'read_time'));
  }

  /**
   * Running twice changes nothing the second time, and says so.
   */
  public function testIsIdempotent(): void {
    $this->migrate([
      'text' => [
        'read_time' => ['id' => 'read_time', 'settings' => []],
      ],
    ]);
    $factory = $this->container->get('config.factory');
    $first = $factory->getEditable(self::NAME)->getRawData();

    $summary = neo_alchemist_update_11007();

    $this->assertSame($first, $factory->getEditable(self::NAME)->getRawData());
    $this->assertStringContainsString('No component read_time providers needed', $summary);
  }

}
