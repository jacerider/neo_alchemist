<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist_test\Plugin\ComponentValue\TestModeSecondValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What a site builder's "Processing" choice actually does to a resolved value.
 *
 * The Unit suite pins the decision — given a mode and an emptiness verdict,
 * does the provider claim? — against a hand-rolled double. This pins the
 * CONSEQUENCE, through the real plugin base, the real claim bookkeeping and
 * the real pipeline: two providers on one prop, the first mode-aware and
 * value-producing, the second unconditionally producing 'SECOND'. If 'SECOND'
 * survives, the first provider did not stop the pipeline.
 *
 * The case that earns the suite its keep is block + nothing produced: the
 * label promises "Always stop (block if empty)", and that must mean the
 * fallback never gets a turn and the prop renders as nothing.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
 * @see \Drupal\Tests\neo_alchemist\Unit\ComponentValueProcessingModeTest
 */
#[Group('neo_alchemist')]
class ComponentValueProcessingModeIntegrationTest extends KernelTestBase {

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
   * Builds the recorder component with both mode providers on `note`.
   *
   * Saved order is first → second, so the only thing that can stop the second
   * provider is the first one claiming the value.
   *
   * @param string $mode
   *   The first provider's processing mode.
   * @param string $produce
   *   What the first provider produces ('' means it produced nothing).
   */
  private function buildComponent(string $mode, string $produce): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_recorder')) {
      Component::create([
        'id' => 'na_recorder',
        'label' => 'Recorder fixture',
        'description' => 'Recorder fixture',
        'component' => 'neo_alchemist_test:na_recorder',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_recorder')
      ->set('settings.props.note.plugins.note', [
        'na_mode_first' => [
          'id' => 'na_mode_first',
          'settings' => ['produce' => $produce, 'processing_mode' => $mode],
        ],
        'na_mode_second' => ['id' => 'na_mode_second', 'settings' => []],
      ])
      ->save();
    $storage->resetCache(['na_recorder']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_recorder');
    return $component;
  }

  /**
   * The end-to-end mode table.
   *
   * @return array
   *   Cases of [mode, produced value, expected resolved value, why].
   */
  public static function modeCases(): array {
    return [
      'stop_when_found + produced => that value wins, later providers blocked' => [
        ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
        'FIRST',
        'FIRST',
        'A value was found, so nothing later may overwrite it.',
      ],
      'stop_when_found + nothing => the later provider fills in' => [
        ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
        '',
        TestModeSecondValue::PRODUCES,
        'Nothing was produced, so the next provider must get a turn.',
      ],
      'continue + produced => the later provider still changes it' => [
        ComponentValueProcessingModeInterface::MODE_CONTINUE,
        'FIRST',
        TestModeSecondValue::PRODUCES,
        'This mode exists precisely to allow later modification.',
      ],
      'block + produced => that value wins' => [
        ComponentValueProcessingModeInterface::MODE_BLOCK,
        'FIRST',
        'FIRST',
        'Block halts the pipeline with the value it produced.',
      ],
      'block + nothing => nothing renders' => [
        ComponentValueProcessingModeInterface::MODE_BLOCK,
        '',
        NULL,
        'Blocking on empty is the whole point of the mode: no fallback, no value.',
      ],
    ];
  }

  /**
   * A configured mode changes what the component resolves.
   */
  #[DataProvider('modeCases')]
  public function testModeChangesResolvedValue(string $mode, string $produce, ?string $expected, string $why): void {
    $component = $this->buildComponent($mode, $produce);

    $values = $component->getPropValues();

    if ($expected === NULL) {
      $this->assertArrayNotHasKey('note', $values, $why);
    }
    else {
      $this->assertSame($expected, $values['note'] ?? NULL, $why);
    }
  }

  /**
   * Shipped plugins that deliberately default to a non-standard mode.
   *
   * These defaults are site-builder-visible behavior: a provider defaulting
   * to block hides the component when it finds nothing, instead of letting a
   * fallback fill in. An accidental change here is silent, so the set is
   * pinned and must be updated consciously.
   *
   * Read statically off the classes rather than from instances, so the pin
   * covers every discovered plugin regardless of which shape it applies to.
   */
  public function testShippedNonDefaultModes(): void {
    $definitions = $this->container->get('plugin.manager.neo_component_value')->getDefinitions();

    $nonDefault = [];
    foreach ($definitions as $pluginId => $definition) {
      $class = $definition['class'] ?? NULL;
      if (!$class || !is_subclass_of($class, ComponentValueProcessingModeInterface::class)) {
        continue;
      }
      $reflection = new \ReflectionClass($class);
      $method = $reflection->getMethod('processingModeDefault');
      $mode = $method->invoke($reflection->newInstanceWithoutConstructor());
      if ($mode !== ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND) {
        $nonDefault[$pluginId] = $mode;
      }
    }
    ksort($nonDefault);

    // ViewsValue also defaults to block but declares provider: 'views', and
    // taxonomy_menu / taxonomy_children live in neo_alchemist_taxonomy, so
    // none of the three are discovered under this suite's minimal module set.
    //
    // What unites this set is the SHAPE of the prop each one fills: a list, a
    // menu, a trail — props whose schema examples are editor scaffolding
    // (invented links, placeholder cards) rather than a usable default. Since
    // getDefaultValue() stopped letting an empty non-claiming producer wipe
    // the seeded example, a claim is the only way such a producer can say
    // "nothing IS the answer", and block is the mode that claims. Producers
    // that fill an authored scalar — entity on a string, heading, page_title —
    // deliberately stay on stop_when_found so the component author's example
    // survives an empty source field.
    //
    // `event` is the one continue, and it is a compatibility pin rather than a
    // shape judgement: the plugin predates the mode and never claimed on its
    // own, deferring entirely to a subscriber's stopFurtherProcessing() call.
    // continue reproduces that exactly, so adopting the trait changed nothing
    // for components already configured with it.
    $this->assertSame([
      'breadcrumb' => ComponentValueProcessingModeInterface::MODE_BLOCK,
      'entity_filter' => ComponentValueProcessingModeInterface::MODE_BLOCK,
      'entity_query' => ComponentValueProcessingModeInterface::MODE_BLOCK,
      'entity_reference' => ComponentValueProcessingModeInterface::MODE_BLOCK,
      'event' => ComponentValueProcessingModeInterface::MODE_CONTINUE,
      'menu' => ComponentValueProcessingModeInterface::MODE_BLOCK,
    ], $nonDefault, 'The set of providers defaulting to a non-standard processing mode changed.');
  }

}
