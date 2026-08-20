<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * What neo_alchemist_update_11004() rewrites, and — mostly — what it does not.
 *
 * The update writes one leaf key, `processing_mode: block`, onto the single
 * plugin per shape whose emptiness decides whether a component in the `entity`
 * group falls back to its schema `examples`. Almost every test here pins
 * something it must NOT touch, because that is where the damage lives: this
 * hook runs unattended against three production sites, and every rule it
 * encodes exists to stop it trading one silent blanking for another.
 *
 * The load-bearing case is ::testChainWithBlockingLastProducerIsUntouched().
 * A `entity → media` chain on an image prop is live config on the site this
 * was written for; setting `block` on the FIRST producer claims the value, so
 * `media` never runs and the image disappears. A naive "set every producer"
 * implementation passes every other test in this file and fails that one.
 *
 * @see neo_alchemist_update_11004()
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::getValueCollection()
 */
#[Group('neo_alchemist')]
class ProcessingModeBackfillTest extends KernelTestBase {

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
    // Saved through the entity API so `settings.props.text` is a real,
    // schema-valid prop skeleton; ::migrate() then swaps only its `plugins`.
    Component::create([
      'id' => 'na_pmode',
      'label' => 'Processing mode fixture',
      'description' => 'Processing mode fixture',
      'component' => 'neo_alchemist_test:na_leaf',
      'status' => TRUE,
    ])->save();
    $this->container->get('module_handler')->loadInclude('neo_alchemist', 'install');
  }

  /**
   * Writes a plugin layout onto the fixture, runs the update, reads it back.
   *
   * Raw config rather than the entity API on purpose: Component::save() writes
   * every plugin's FULL configuration, so an absent `processing_mode` — the
   * state the three sites are actually in, and the state this hook exists for
   * — cannot be produced through it.
   *
   * @param array $shapes
   *   Plugin instances keyed by shape id, so one call can lay out both the
   *   prop's own shape (`text`) and a nested child (`text~child`).
   * @param string $group
   *   The component group.
   *
   * @return array
   *   The fixture's `settings.props.text.plugins` after the update ran.
   */
  private function migrate(array $shapes, string $group = 'entity'): array {
    $config = $this->container->get('config.factory')->getEditable(self::NAME);
    $data = $config->getRawData();
    $data['group'] = $group;
    // Component::save() writes no `settings.props` entry for a prop nobody has
    // configured, so the skeleton is built here rather than borrowed.
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

    neo_alchemist_update_11004();

    return $this->container->get('config.factory')
      ->getEditable(self::NAME)
      ->getRawData()['settings']['props']['text']['plugins'];
  }

  /**
   * Builds one plugin instance, optionally with a stored mode.
   */
  private static function instance(string $id, ?string $mode = NULL, array $settings = []): array {
    if ($mode !== NULL) {
      $settings['processing_mode'] = $mode;
    }
    return ['id' => $id, 'settings' => $settings];
  }

  /**
   * Reads a stored mode back, or NULL when the key was never written.
   */
  private static function mode(array $plugins, string $shapeId, string $pluginId): ?string {
    return $plugins[$shapeId][$pluginId]['settings']['processing_mode'] ?? NULL;
  }

  /**
   * The plain case: one producer, no stored mode, entity group.
   */
  public function testSoleProducerIsBlocked(): void {
    $plugins = $this->migrate([
      'text' => ['entity' => self::instance('entity', NULL, ['field' => 'field_x'])],
    ]);

    $this->assertSame('block', self::mode($plugins, 'text', 'entity'));
  }

  /**
   * A nested shape id is reached — the case 11003 structurally could not.
   *
   * 11003 only ever looked at `$prop['plugins'][$propId]`, so a sub-prop bound
   * to an entity field — which stores its provider under `heading~title` — was
   * invisible to it. That is `callout_s2_2` on the site this was written for,
   * rendering the literal "Example title" on every node whose source field is
   * empty.
   */
  public function testNestedShapeIdIsBlocked(): void {
    $plugins = $this->migrate([
      'text~child' => ['entity' => self::instance('entity', NULL, ['field' => 'field_x'])],
    ]);

    $this->assertSame('block', self::mode($plugins, 'text~child', 'entity'));
  }

  /**
   * A chain whose last producer already blocks is left completely alone.
   *
   * THE regression test. `entity → media(block)` is live config on two image
   * props. Blocking `entity` claims the value, `media` never runs, and the
   * image vanishes — so the rule has to be "the last producer", not "every
   * producer that could take a mode".
   */
  public function testChainWithBlockingLastProducerIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => [
        'entity' => self::instance('entity', NULL, ['field' => 'field_x']),
        'media' => self::instance('media', 'block'),
      ],
    ]);

    $this->assertNull(
      self::mode($plugins, 'text', 'entity'),
      'The first producer in a chain was blocked, so the second can never run.',
    );
    $this->assertSame('block', self::mode($plugins, 'text', 'media'));
  }

  /**
   * In an unblocked chain only the last producer is rewritten.
   */
  public function testChainBlocksOnlyTheLastProducer(): void {
    $plugins = $this->migrate([
      'text' => [
        'entity' => self::instance('entity', NULL, ['field' => 'field_x']),
        'media' => self::instance('media'),
      ],
    ]);

    $this->assertNull(self::mode($plugins, 'text', 'entity'));
    $this->assertSame('block', self::mode($plugins, 'text', 'media'));
  }

  /**
   * A shape carrying a configured Default Value is skipped entirely.
   *
   * Blocking claims the value, so the `fallback` loop that would have supplied
   * the default never runs — the same silent blanking this hook exists to stop,
   * only pointing the other way.
   */
  public function testShapeWithFallbackPluginIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => [
        'entity' => self::instance('entity', NULL, ['field' => 'field_x']),
        'default' => self::instance('default', NULL, ['field_type' => 'string']),
      ],
    ]);

    $this->assertNull(self::mode($plugins, 'text', 'entity'));
  }

  /**
   * Components outside the entity group are out of scope.
   *
   * For a `general` component the schema example IS the author's default, and
   * showing it is the point.
   */
  public function testNonEntityGroupIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => ['entity' => self::instance('entity', NULL, ['field' => 'field_x'])],
    ], 'general');

    $this->assertNull(self::mode($plugins, 'text', 'entity'));
  }

  /**
   * A producer whose class defaults to `continue` is left alone.
   *
   * `event` contributes a value later providers may still change; forcing
   * `block` would both claim on the first pass and turn "no subscriber
   * answered" into "render nothing".
   */
  public function testContinueByDefaultProducerIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => ['event' => self::instance('event')],
    ]);

    $this->assertNull(self::mode($plugins, 'text', 'event'));
  }

  /**
   * An explicitly stored `continue` is never overwritten.
   */
  public function testExplicitContinueIsUntouched(): void {
    $plugins = $this->migrate([
      'text' => ['entity' => self::instance('entity', 'continue', ['field' => 'field_x'])],
    ]);

    $this->assertSame('continue', self::mode($plugins, 'text', 'entity'));
  }

  /**
   * An unresolvable plugin id makes the whole shape untouchable.
   *
   * A disabled module means the pipeline order — and therefore which plugin is
   * last — cannot be trusted, so guessing is worse than doing nothing.
   */
  public function testUnknownPluginSkipsTheWholeShape(): void {
    $plugins = $this->migrate([
      'text' => [
        'entity' => self::instance('entity', NULL, ['field' => 'field_x']),
        'na_not_a_plugin' => ['id' => 'na_not_a_plugin', 'settings' => []],
      ],
    ]);

    $this->assertNull(self::mode($plugins, 'text', 'entity'));
  }

  /**
   * The heading's per-sub-prop mode follows isBlockedEmptyField() exactly.
   *
   * Only a sub-prop with a field binding and no higher-precedence source above
   * it ever has its `_field_mode` read, so writing the key anywhere else would
   * be config that lies about what runs.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue::isBlockedEmptyField()
   */
  public function testHeadingSubPropFieldModes(): void {
    $plugins = $this->migrate([
      'heading' => [
        'heading' => self::instance('heading', NULL, [
          // Bound, nothing above it — the only case that is rewritten.
          'title_field' => 'field_title',
          'title_field_mode' => 'stop_when_found',
          // Bound, but the page title wins as a source.
          'supertitle_field' => 'field_super',
          'supertitle_page' => TRUE,
          'supertitle_field_mode' => 'stop_when_found',
          // Nothing bound.
          'subtitle_field' => '',
          'subtitle_field_mode' => 'stop_when_found',
        ]),
      ],
    ]);
    $settings = $plugins['heading']['heading']['settings'];

    $this->assertSame('block', $settings['title_field_mode']);
    $this->assertSame('stop_when_found', $settings['supertitle_field_mode'], 'A page-title source outranks the field binding.');
    $this->assertSame('stop_when_found', $settings['subtitle_field_mode'], 'Nothing is bound, so the mode is never read.');
  }

  /**
   * The heading's own plugin-wide mode goes through the ordinary rules.
   *
   * It needs no special case: `heading` is a `providers` plugin like any other,
   * so being the sole producer on its shape is what earns it the rewrite.
   */
  public function testHeadingPluginWideModeIsBlocked(): void {
    $plugins = $this->migrate([
      'heading' => ['heading' => self::instance('heading')],
    ]);

    $this->assertSame('block', self::mode($plugins, 'heading', 'heading'));
  }

  /**
   * A provider's per-child `shape_fields` plugins are reached, if enabled.
   *
   * These carry a different key shape from a top-level instance — `plugin_id`
   * rather than `id`, plus a `status` flag — and a disabled one is never handed
   * to the child shape, so it is not in the pipeline and cannot be its last
   * producer.
   */
  public function testShapeFieldsRecursion(): void {
    $plugins = $this->migrate([
      'text' => [
        'entity_query' => self::instance('entity_query', 'block', [
          'shape_fields' => [
            'live' => [
              'plugins' => [
                'breadcrumb' => [
                  'plugin_id' => 'breadcrumb',
                  'status' => TRUE,
                  'settings' => ['processing_mode' => 'stop_when_found'],
                ],
              ],
            ],
            'disabled' => [
              'plugins' => [
                'breadcrumb' => [
                  'plugin_id' => 'breadcrumb',
                  'status' => FALSE,
                  'settings' => ['processing_mode' => 'stop_when_found'],
                ],
              ],
            ],
          ],
        ]),
      ],
    ]);
    $fields = $plugins['text']['entity_query']['settings']['shape_fields'];

    $this->assertSame('block', $fields['live']['plugins']['breadcrumb']['settings']['processing_mode']);
    $this->assertSame(
      'stop_when_found',
      $fields['disabled']['plugins']['breadcrumb']['settings']['processing_mode'],
      'A disabled child plugin never reaches the pipeline, so its mode is not the hook\'s business.',
    );
  }

  /**
   * Running twice changes nothing the second time.
   *
   * The rules read the STORED mode rather than the effective one, which is what
   * makes this true — and what stops a re-run from overruling a choice someone
   * made by hand in between.
   */
  public function testIsIdempotent(): void {
    $this->migrate([
      'text' => [
        'entity' => self::instance('entity', NULL, ['field' => 'field_x']),
      ],
      'text~child' => [
        'media' => self::instance('media'),
      ],
    ]);
    $factory = $this->container->get('config.factory');
    $first = $factory->getEditable(self::NAME)->getRawData();

    $summary = neo_alchemist_update_11004();

    $this->assertSame($first, $factory->getEditable(self::NAME)->getRawData());
    $this->assertStringContainsString('No entity-group component props needed migrating', $summary);
  }

  /**
   * Which plugins expose a processing mode at all.
   *
   * The hook detects this from the interface rather than a list of ids, so this
   * pins the mechanism's ANSWER rather than the mechanism. It goes red the
   * moment a plugin adopts the interface — which is the prompt to ask whether
   * that plugin also needs a migration of its own.
   *
   * Absent by design: `views` declares `provider: 'views'` and
   * `taxonomy_menu`/`taxonomy_children` ship in neo_alchemist_taxonomy, so none
   * of the three is discovered with this module list. They are still covered on
   * a site that has them — nothing here is keyed by plugin id.
   */
  public function testModeCapablePluginRoster(): void {
    $definitions = $this->container->get('plugin.manager.neo_component_value')->getDefinitions();
    $capable = array_keys(array_filter(
      $definitions,
      static fn (array $definition): bool => is_subclass_of($definition['class'], ComponentValueProcessingModeInterface::class),
    ));
    sort($capable);

    $this->assertSame([
      'breadcrumb',
      'entity',
      'entity_filter',
      'entity_load',
      'entity_query',
      'entity_reference',
      'event',
      'heading',
      'media',
      'menu',
      'na_mode_first',
      'page_title',
      'share',
    ], $capable);
  }

}
