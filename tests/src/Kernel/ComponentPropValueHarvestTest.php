<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The rules a submitted prop value has to survive on its way to storage.
 *
 * These rules used to live inline in InstanceComponentForm::validateForm(),
 * with SdcPreviewForm carrying a copy and a comment pointing at the original.
 * Each is small and none is obvious, and the two copies had nothing holding
 * them together. ComponentPropValueHarvester owns them now, which is what
 * makes them reachable from a test at all: previously the only way to exercise
 * them was to stand up one of the two forms.
 *
 * Asserted through the harvester's return value only — which props it produced
 * and what is in them. Which shape methods it called, and in what order, is its
 * own business.
 *
 * @see \Drupal\neo_alchemist\ComponentPropValueHarvester::harvest()
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueEditorHarvestWiringTest
 *   Covers the other half: that both editors feed the result to their sink.
 */
#[Group('neo_alchemist')]
class ComponentPropValueHarvestTest extends KernelTestBase {

  use ValueEditorFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * A single string prop, the simplest thing a value editor can edit.
   */
  private const LEAF_SDC = 'neo_alchemist_test:na_leaf';

  /**
   * An array of bare strings, for the rules that turn on iterability.
   */
  private const ARRAY_SDC = 'neo_alchemist_test:na_array_single';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installValueEditorHost();
  }

  /**
   * Builds a component for an SDC, in the preview mode the workspace uses.
   *
   * Preview mode is what routes getValues() to the cache-backed overrides,
   * which is how a test turns a per-prop option on without a form submission.
   *
   * @param string $sdcId
   *   The SDC to build a component for.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component, with its shapes freshly built.
   */
  private function component(string $sdcId): ComponentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Harvest fixture',
      'description' => 'Harvest fixture',
      'component' => $sdcId,
      'status' => TRUE,
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    // Shape state is memoised per object, so never share one between passes.
    $id = $component->id();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\ComponentInterface $component */
    $component = $storage->load($id);
    $component->setPreview(TRUE);
    $component->resetPreviewValues();
    return $component;
  }

  /**
   * Builds the value panel exactly as an editor does.
   *
   * Going through the real builder rather than hand-rolling an element tree is
   * what makes the `#parents` the harvester's subform states resolve against
   * the ones production produces.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being edited.
   * @param array $submitted
   *   The submitted values, relative to the `values` element.
   * @param \Drupal\Core\Form\FormState|null $formState
   *   Returns the form state, for callers that need to keep driving it.
   *
   * @return array
   *   The form the harvester reads.
   */
  private function buildPanel(ComponentInterface $component, array $submitted, ?FormState &$formState = NULL): array {
    $formState = new FormState();
    // The form builder always seeds these; a bare FormState does not, and the
    // shapes' own validateForm() strips its option controls out of both.
    $formState->setUserInput([]);
    $form = ['#parents' => []];
    $panel = $this->container->get('neo_alchemist.value_panel_builder')
      ->build($component, $form, $formState);
    $form['styles'] = $panel['styles'];
    $form['values'] = $panel['values'];
    $formState->setValues(['values' => $submitted]);
    return $form;
  }

  /**
   * Harvests a submission, the way both value editors do.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being edited.
   * @param array $submitted
   *   The submitted values, relative to the `values` element.
   * @param array $original
   *   The values the editor opened on, keyed the way a component's are.
   *
   * @return array
   *   The harvested props structure.
   */
  private function harvest(ComponentInterface $component, array $submitted, array $original = []): array {
    $form = $this->buildPanel($component, $submitted, $formState);
    return $this->container->get('neo_alchemist.prop_value_harvester')
      ->harvest($component, $form, $formState, $original);
  }

  /**
   * The panel offers the prop the rest of this file submits against.
   *
   * Without this the tests below could pass by harvesting nothing at all — an
   * empty result satisfies "the old value was not clobbered" just as well as a
   * correct one does.
   */
  public function testFixtureExposesAnEditableProp(): void {
    $component = $this->component(self::LEAF_SDC);
    $shape = $component->getPropShapes()['text'] ?? NULL;

    $this->assertNotNull($shape, 'The fixture exposes its string prop.');
    $this->assertTrue($shape->access('update'), 'The account may update it.');
    $this->assertFalse($shape->isIterable(), 'It is a non-iterable shape, which is what the union rule turns on.');

    $props = $this->harvest($component, $this->stringSubmission('text', 'SUBMITTED'));
    $this->assertArrayHasKey('text', $props, 'The prop is harvested.');
    $this->assertSame($shape->getRef(), $props['text']['ref'], 'The harvest records the shape it came from.');
  }

  /**
   * A prop whose stored value is a scalar survives the harvest.
   *
   * The rule with the highest cost of being got wrong: massageFormValues() is
   * typed array both ways, so handing it a scalar is a fatal — and because
   * every validation of a value editor runs through the harvest, that fatal
   * takes Save down with it, not just validation. The guard is what keeps the
   * scalar out of that path.
   */
  public function testScalarStoredValueDoesNotFatal(): void {
    $component = $this->component(self::LEAF_SDC);

    $props = $this->harvest(
      $component,
      $this->stringSubmission('text', 'SUBMITTED'),
      ['props' => ['text' => ['value' => 'PREVIOUS SCALAR']]],
    );

    $this->assertArrayHasKey('text', $props, 'The prop was harvested rather than fataling.');
    $this->assertNotSame(
      'PREVIOUS SCALAR',
      $props['text']['value'],
      'With no default enabled the submission wins; the scalar is only restored below.',
    );
  }

  /**
   * A scalar stored value is handed back when the prop's default is on.
   *
   * Restoring the previous value is the only reason the original is threaded
   * through the loop, and the massage step's array return type cannot carry a
   * scalar — so the restore has to happen after it, from the untouched
   * original rather than from anything the massage produced.
   */
  public function testScalarIsRestoredWhenDefaultIsEnabled(): void {
    $component = $this->component(self::LEAF_SDC);
    $shape = $component->getPropShapes()['text'];
    $this->assertFalse(
      $shape->getOptionDefault()->isEnabled(),
      'Premise: the default starts off, so the restore below can only come from turning it on.',
    );

    // Turning the option on is what an author does with the Default checkbox;
    // going through the override is how that reaches a rebuilt shape.
    $component->setPreviewValues([
      'props' => [
        'text' => [
          'ref' => $shape->getRef(),
          'value' => ['value' => 'IGNORED'],
          'options' => [$shape->id() => ['default' => 1]],
        ],
      ],
    ]);
    $this->assertTrue($component->getPropShapes()['text']->getOptionDefault()->isEnabled());

    $props = $this->harvest(
      $component,
      $this->stringSubmission('text', 'SUBMITTED'),
      ['props' => ['text' => ['value' => 'PREVIOUS SCALAR']]],
    );

    $this->assertSame(
      'PREVIOUS SCALAR',
      $props['text']['value'],
      'The author gets back what they typed before enabling the default.',
    );
  }

  /**
   * A non-iterable shape keeps original keys the submission did not carry.
   *
   * Editing one part of a prop must not clear the rest of it. The union is
   * scoped to non-iterable shapes with a non-empty result, both of which are
   * pinned here and in the test below.
   */
  public function testNonIterableShapeUnionsTheOriginal(): void {
    $component = $this->component(self::LEAF_SDC);

    $props = $this->harvest(
      $component,
      $this->stringSubmission('text', 'SUBMITTED'),
      ['props' => ['text' => ['value' => ['value' => 'PREVIOUS', 'kept' => 'KEPT']]]],
    );

    $this->assertSame('SUBMITTED', $props['text']['value']['value'], 'The submitted key wins.');
    $this->assertSame('KEPT', $props['text']['value']['kept'], 'The untouched key survives.');
  }

  /**
   * An iterable shape does not union, so a shortened list stays short.
   *
   * The union would merge the original's higher deltas back in, and a list the
   * author shortened would grow its removed items back on save.
   */
  public function testIterableShapeDoesNotUnionTheOriginal(): void {
    $component = $this->component(self::ARRAY_SDC);
    $shape = $component->getPropShapes()['items'] ?? NULL;
    $this->assertNotNull($shape, 'The fixture exposes its array prop.');
    $this->assertTrue($shape->isIterable(), 'Premise: the union rule is scoped to non-iterable shapes.');

    $props = $this->harvest(
      $component,
      ['items' => [0 => ['_weight' => '0'] + $this->stringSubmission('value', 'ONLY ITEM')]],
      ['props' => ['items' => ['value' => [0 => ['value' => 'OLD 0'], 1 => ['value' => 'OLD 1']]]]],
    );

    $this->assertArrayNotHasKey(
      1,
      $props['items']['value'],
      'The delta the author removed did not come back through a union.',
    );
  }

  /**
   * A prop the account may not update is left out of the harvest entirely.
   *
   * The gate travelled with the loop rather than staying behind as an
   * inference about how the form was built, so it holds even when the form
   * still carries the prop — which is exactly the window a rebuild opens.
   */
  public function testPropWithoutUpdateAccessIsNotHarvested(): void {
    $component = $this->component(self::LEAF_SDC);
    $form = $this->buildPanel($component, $this->stringSubmission('text', 'SUBMITTED'), $formState);
    $this->assertArrayHasKey('text', $form['values'], 'Premise: the form does carry the prop.');

    // The shapes are memoized, so this is the same object the harvest reads.
    $component->getPropShapes()['text']->setEditable(FALSE);
    $this->assertFalse($component->getPropShapes()['text']->access('update'));

    $props = $this->container->get('neo_alchemist.prop_value_harvester')
      ->harvest($component, $form, $formState, []);

    $this->assertArrayNotHasKey('text', $props, 'Nothing is harvested for it, so its stored value is untouched.');
  }

  /**
   * The prop's nested options accompany its value.
   *
   * They are what carries per-prop state — a default turned off to reveal a
   * media widget, most consequentially — from one request to the next, so a
   * harvest that produced only values would silently drop it.
   */
  public function testNestedOptionsAccompanyTheValue(): void {
    $component = $this->component(self::LEAF_SDC);
    $shape = $component->getPropShapes()['text'];
    $component->setPreviewValues([
      'props' => [
        'text' => [
          'ref' => $shape->getRef(),
          'value' => ['value' => 'ANYTHING'],
          'options' => [$shape->id() => ['default' => 1]],
        ],
      ],
    ]);

    $props = $this->harvest($component, $this->stringSubmission('text', 'SUBMITTED'));

    $this->assertSame(
      $component->getPropShapes()['text']->getNestedOptionMap()->toArray(),
      $props['text']['options'],
      'The harvest reports the shape\'s nested options verbatim.',
    );
    $this->assertNotSame([], $props['text']['options'], 'And there is something in them to report.');
  }

  /**
   * A component with nothing to harvest produces an empty array.
   *
   * Both callers branch on this to leave their `props` key unset — an empty
   * one is not the same thing to either sink, and in the preview workspace it
   * is the difference between the Reset button being offered and not.
   */
  public function testNothingHarvestableProducesAnEmptyArray(): void {
    $component = $this->component(self::LEAF_SDC);
    $component->getPropShapes()['text']->setEditable(FALSE);

    $this->assertSame([], $this->harvest($component, $this->stringSubmission('text', 'SUBMITTED')));
  }

}
