<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist_test\TestValueCallLog;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the getDefaultValue() call discipline.
 *
 * Three invariants:
 * - The default is computed exactly once per shape. The memo previously
 *   guarded with isset(), so a computed-NULL default (any provider chain
 *   ending in NULL — MediaValue, DefaultValue, EventValue can all yield it)
 *   was recomputed on every call.
 * - Recomputation is not harmless: the pipeline sets the field item as a
 *   side effect ("Set the value so providers can use it"), so a post-init
 *   re-entry — buildDefaultValue() runs during child-schema loading —
 *   overwrote an authored field item value with the recomputed NULL.
 * - The alter pass runs after the provide pass, on a freshly fetched
 *   instance list (a claim in the provide loop must not truncate it), and
 *   providers run before the fallback group regardless of the saved plugin
 *   order.
 *
 * Red/green proof performed during development: with the pre-fix
 * `if (!isset($this->defaultValue))` guard restored,
 * testDefaultValueComputedOnce and
 * testRecomputeDoesNotClobberAuthoredFieldItemValue go red.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getDefaultValue()
 */
#[Group('neo_alchemist')]
class GetDefaultValueOrderTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestValueCallLog::reset();
  }

  /**
   * Builds the recorder component and returns its initialized `note` shape.
   *
   * The recording plugins are activated by raw config after the first save —
   * Component::preSave() regenerates settings.props on save, wiping hand-set
   * plugin config — with the fallback plugin deliberately listed FIRST in
   * the saved order, which the group ordering must override.
   */
  private function buildNoteShape(): ComponentShapePluginInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_recorder')) {
      Component::create([
        'id' => 'na_recorder',
        'label' => 'Recorder fixture',
        'description' => 'Recorder fixture',
        'component' => 'neo_alchemist_test:na_recorder',
        'status' => TRUE,
      ])->save();
      $this->container->get('config.factory')
        ->getEditable('neo_alchemist.neo_component.na_recorder')
        ->set('settings.props.note.plugins.note', [
          'na_record_fallback' => ['id' => 'na_record_fallback', 'settings' => []],
          'na_record_provider' => ['id' => 'na_record_provider', 'settings' => []],
          'na_record_modifier' => ['id' => 'na_record_modifier', 'settings' => []],
        ])
        ->save();
    }
    $storage->resetCache(['na_recorder']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_recorder');
    TestValueCallLog::reset();
    $shape = $component->getPropShapes()['note'] ?? NULL;
    $this->assertInstanceOf(ComponentShapePluginInterface::class, $shape, 'Premise: the note shape resolved.');
    $this->assertNotEmpty(TestValueCallLog::$calls, 'Premise: the recording plugins are active on the shape.');
    return $shape;
  }

  /**
   * The default value is computed exactly once per shape.
   *
   * The note prop has no examples and the fallback plugin yields NULL for
   * empty values, so the computed default is NULL — the exact value the
   * isset()-based memo could not hold.
   */
  public function testDefaultValueComputedOnce(): void {
    $shape = $this->buildNoteShape();

    // init() already ran the pipeline once. Any further call must be a memo
    // hit, not a recomputation.
    $shape->buildDefaultValue();
    $shape->buildDefaultValue();

    $computations = TestValueCallLog::callsFor('provideDefaultValue');
    $fallbackRuns = array_filter($computations, static fn (string $call): bool => str_starts_with($call, 'na_record_fallback:'));

    $this->assertCount(1, $fallbackRuns, 'The default pipeline ran exactly once; a NULL default memoises like any other.');
  }

  /**
   * A post-init re-entry cannot clobber an authored field item value.
   *
   * This is the silent-loss shape of the memo miss: getDefaultValue() sets
   * the field item as a side effect, and it is re-entered after init — e.g.
   * from ArrayShape/ObjectShape::loadChildSchema() — while the shape already
   * holds authored content.
   */
  public function testRecomputeDoesNotClobberAuthoredFieldItemValue(): void {
    $shape = $this->buildNoteShape();

    $shape->setFieldItemValue('AUTHORED');
    $shape->buildDefaultValue();

    $this->assertSame(['value' => 'AUTHORED'], $shape->getFieldItemValue(), 'The authored value survived a post-init default-value call.');
  }

  /**
   * Provide pass order and the subsequent alter pass.
   *
   * Providers run before the fallback group even though the saved plugin
   * order lists the fallback first; and the alter pass runs after every
   * provide call, for every instance.
   */
  public function testAlterPassRunsAfterProvidePass(): void {
    $this->buildNoteShape();

    $provides = TestValueCallLog::callsFor('provideDefaultValue');
    $alters = TestValueCallLog::callsFor('alterValue');

    $this->assertSame([
      'na_record_provider:provideDefaultValue',
      'na_record_fallback:provideDefaultValue',
      'na_record_modifier:provideDefaultValue',
    ], $provides, 'The providers group ran before fallback, then modifiers — never the saved order.');

    $this->assertNotEmpty($alters, 'The alter pass ran.');
    $lastProvide = array_search(end($provides), TestValueCallLog::$calls, TRUE);
    $firstAlterKeys = array_keys(TestValueCallLog::$calls, $alters[0], TRUE);
    $this->assertGreaterThan($lastProvide, $firstAlterKeys[0], 'Alter runs only after every provide call.');
  }

  /**
   * Modifiers run during value resolution, after every provide call.
   *
   * The modify pass belongs to rendering — resolving the prop values runs
   * it once the providers and fallback have settled the value. This is the
   * whole pipeline end to end through getPropValues().
   */
  public function testModifierRunsDuringResolutionAfterProviders(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $this->buildNoteShape();
    $storage->resetCache(['na_recorder']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_recorder');
    TestValueCallLog::reset();

    $component->getPropValues();

    $modifies = TestValueCallLog::callsFor('modifyValue');
    $provides = TestValueCallLog::callsFor('provideDefaultValue');
    $this->assertSame(['na_record_modifier:modifyValue'], $modifies, 'The modifier ran exactly once during resolution.');
    $this->assertNotEmpty($provides, 'The provide pass ran.');
    $lastProvide = array_search(end($provides), TestValueCallLog::$calls, TRUE);
    $modifyKeys = array_keys(TestValueCallLog::$calls, $modifies[0], TRUE);
    $this->assertGreaterThan($lastProvide, $modifyKeys[0], 'The modifier ran only after the value was settled.');
  }

}
