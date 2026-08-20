<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist_test\Plugin\ComponentValue\TestModeSecondValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * What an OPTIONAL prop resolves to when its producer comes up empty.
 *
 * The pipeline seeds every prop from its schema examples and then runs the
 * producers. A producer that finds nothing and does not claim contributes
 * nothing, so the seed must survive it: enabling a provider must not leave a
 * prop worse off than never having enabled one.
 *
 * The bug this pins: a component rendered its author's label until an Entity
 * Field provider was attached to that prop, whereupon a term with an empty
 * source field rendered nothing at all — the provider returned the field's
 * empty value, that empty overwrote the seeded example, and because the prop
 * was not required the example was never restored. Nothing in the saved config
 * showed that a value had been thrown away.
 *
 * The counterweight is block: "Always stop (block if empty)" is how a producer
 * says nothing IS the answer, and it must still win — that is the mode a prop
 * whose examples are editor scaffolding (placeholder cards, invented menu
 * links) belongs on, and the default for the producers that fill those props.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::getDefaultValue()
 * @see \Drupal\Tests\neo_alchemist\Kernel\GetDefaultValueRequiredFallbackTest
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProcessingModeIntegrationTest
 */
#[Group('neo_alchemist')]
class GetDefaultValueEmptyProducerTest extends KernelTestBase {

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
   * The schema example seeded onto the optional `label` prop.
   */
  private const EXAMPLE = 'EXAMPLE LABEL';

  /**
   * Builds the optional-prop probe with the given plugins on `label`.
   *
   * @param array $plugins
   *   Raw plugin config, keyed by plugin id, in saved order.
   */
  private function buildComponent(array $plugins): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_example_probe')) {
      Component::create([
        'id' => 'na_example_probe',
        'label' => 'Example probe fixture',
        'description' => 'Example probe fixture',
        'component' => 'neo_alchemist_test:na_example_probe',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_example_probe')
      ->set('settings.props.label.plugins.label', $plugins)
      ->save();
    $storage->resetCache(['na_example_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_example_probe');
    return $component;
  }

  /**
   * Config for the first mode provider.
   */
  private static function first(array $settings): array {
    return ['na_mode_first' => ['id' => 'na_mode_first', 'settings' => $settings]];
  }

  /**
   * A producer that resolved nothing leaves the seeded example in place.
   *
   * The regression test. Red before the fix with the prop missing entirely
   * from the resolved values — the visible symptom being a heading that
   * vanished from the page the moment a provider was attached to it.
   */
  public function testEmptyProducerKeepsTheSeededExample(): void {
    $component = $this->buildComponent(self::first([
      'produce_empty' => TRUE,
      'processing_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
    ]));

    $values = $component->getPropValues();

    $this->assertSame(self::EXAMPLE, $values['label'] ?? NULL, 'An optional prop kept its schema example when the producer found nothing.');
  }

  /**
   * Blocking on empty still renders nothing.
   *
   * The other half of the contract, and the escape hatch for every prop whose
   * examples are scaffolding: if this ever goes green with the example, lists
   * and menus start showing invented content to visitors.
   */
  public function testBlockingProducerStillResolvesToNothing(): void {
    $component = $this->buildComponent(self::first([
      'produce_empty' => TRUE,
      'processing_mode' => ComponentValueProcessingModeInterface::MODE_BLOCK,
    ]));

    $values = $component->getPropValues();

    $this->assertArrayNotHasKey('label', $values, 'A blocking producer that found nothing kept the prop empty.');
  }

  /**
   * A produced value still beats the example.
   *
   * The invariant: preserving the seed must not make it sticky.
   */
  public function testProducedValueStillWins(): void {
    $component = $this->buildComponent(self::first([
      'produce' => 'FIRST',
      'processing_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
    ]));

    $values = $component->getPropValues();

    $this->assertSame('FIRST', $values['label'] ?? NULL, 'The producer’s value replaced the schema example.');
  }

  /**
   * A resolved '0' is a found value, not an empty one.
   *
   * The preservation test is isProvidedValueEmpty(), not PHP truthiness. Under
   * raw truthiness this is exactly where a legitimate zero would be discarded
   * and the component's placeholder shown in place of real content — the same
   * substitution the falsy sweep removed everywhere else in the pipeline.
   */
  public function testProducedZeroSurvivesInsteadOfTheExample(): void {
    $component = $this->buildComponent(self::first([
      'produce' => '0',
      'processing_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
    ]));

    $values = $component->getPropValues();

    $this->assertSame('0', $values['label'] ?? NULL, 'A produced "0" survived rather than being read as empty.');
  }

  /**
   * Preserving the seed does not stop the search.
   *
   * Distinguishes "kept the value threaded into it" from "claimed it": the
   * later producer must still get its turn and overwrite the example.
   */
  public function testEmptyProducerStillLetsTheNextProducerFillIn(): void {
    $component = $this->buildComponent(self::first([
      'produce_empty' => TRUE,
      'processing_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
    ]) + ['na_mode_second' => ['id' => 'na_mode_second', 'settings' => []]]);

    $values = $component->getPropValues();

    $this->assertSame(TestModeSecondValue::PRODUCES, $values['label'] ?? NULL, 'The next producer still ran and replaced the preserved example.');
  }

}
