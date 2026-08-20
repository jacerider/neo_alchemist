<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * What an emptied Default Value provider resolves to.
 *
 * The pipeline seeds every prop from its schema examples and then discards an
 * empty answer from any producer that did not claim, so that a provider which
 * found nothing does not destroy the seed on its way past. The `default` plugin
 * is not such a producer: it is not looking anything up, it IS the configured
 * default, so its empty means "no default" rather than "I came up short" and it
 * has to claim to say so.
 *
 * The bug this pins: a site-builder enabled Default Value on an array prop and
 * deleted every item. The plugin duly returned [], the provide loop read that
 * as a producer coming up empty and restored the seed, and the component
 * author's example call-to-action button reappeared in the preview —
 * indistinguishable from the deletion never having saved.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\DefaultValue::provideDefaultValue()
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getDefaultValue()
 * @see \Drupal\Tests\neo_alchemist\Kernel\GetDefaultValueEmptyProducerTest
 */
#[Group('neo_alchemist')]
class DefaultValueEmptyConfiguredTest extends KernelTestBase {

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
   * The schema examples seeded onto the optional `items` prop.
   */
  private const EXAMPLES = ['EXAMPLE ITEM 0', 'EXAMPLE ITEM 1'];

  /**
   * Builds the array probe with the given plugins on `items`.
   *
   * @param array|null $plugins
   *   Raw plugin config keyed by plugin id, or NULL for a prop that has never
   *   had a value plugin attached at all.
   */
  private function buildComponent(?array $plugins): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_single')) {
      Component::create([
        'id' => 'na_array_single',
        'label' => 'Array single fixture',
        'description' => 'Array single fixture',
        'component' => 'neo_alchemist_test:na_array_single',
        'status' => TRUE,
      ])->save();
    }
    $config = $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_array_single');
    if ($plugins === NULL) {
      $config->clear('settings.props.items.plugins')->save();
    }
    else {
      $config->set('settings.props.items.plugins.items', $plugins)->save();
    }
    $storage->resetCache(['na_array_single']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_single');
    return $component;
  }

  /**
   * Config for the Default Value provider holding the given default.
   *
   * NULL is the "nothing configured" sentinel the widget's `- Default -` choice
   * is massaged onto; every other value is an answer somebody typed.
   */
  private static function defaultPlugin(mixed $default): array {
    return [
      'default' => [
        'id' => 'default',
        'settings' => [
          'field_type' => 'map',
          'default' => $default,
          'options' => [],
        ],
      ],
    ];
  }

  /**
   * An emptied default resolves to nothing, not to the schema examples.
   *
   * The regression test. Red before the fix with the prop resolving to the two
   * EXAMPLES — the visible symptom being a call-to-action button that came back
   * every time the site-builder deleted it.
   */
  public function testEmptiedDefaultBeatsTheSchemaExamples(): void {
    $component = $this->buildComponent(self::defaultPlugin([]));

    $values = $component->getPropValues();

    $this->assertEmpty($values['items'] ?? [], 'An emptied Default Value provider resolved to nothing rather than restoring the component author’s examples.');
  }

  /**
   * A prop with no value plugin at all still shows its examples.
   *
   * The counterweight. Claiming an empty default must not become "arrays
   * resolve to nothing": a prop nobody has configured is still scaffolded by
   * whatever the component author declared.
   */
  public function testUnconfiguredPropKeepsItsExamples(): void {
    $component = $this->buildComponent(NULL);

    $values = $component->getPropValues();

    $this->assertSame(self::EXAMPLES, $values['items'] ?? NULL, 'A prop with no value plugin kept its schema examples.');
  }

  /**
   * The `- Default -` sentinel keeps the examples too.
   *
   * Distinguishes "enabled the provider and emptied it" from "enabled the
   * provider and asked for the schema default". Both store a falsy value; only
   * one of them means the prop should render nothing.
   */
  public function testNullSentinelKeepsTheExamples(): void {
    $component = $this->buildComponent(self::defaultPlugin(NULL));

    $values = $component->getPropValues();

    $this->assertSame(self::EXAMPLES, $values['items'] ?? NULL, 'A default of NULL fell through to the schema examples.');
  }

  /**
   * A configured non-empty default still wins.
   *
   * The invariant: the claim must not change what a populated default does.
   */
  public function testConfiguredDefaultStillWins(): void {
    $component = $this->buildComponent(self::defaultPlugin([
      ['value' => 'CONFIGURED ITEM'],
    ]));

    $values = $component->getPropValues();

    $this->assertSame(['CONFIGURED ITEM'], $values['items'] ?? NULL, 'A populated default replaced the schema examples.');
  }

}
