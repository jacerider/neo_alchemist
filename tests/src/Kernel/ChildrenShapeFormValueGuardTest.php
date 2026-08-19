<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Scopes the dropped-value guard in getChildShapes() to the ordering it is for.
 *
 * The guard exists because priming an iterable parent's per-delta child shapes
 * *before* the value arrives leaves every provider-owning child refusing the
 * later push and resolving to nothing.
 *
 * @see \Drupal\Tests\neo_alchemist\Kernel\ChildrenShapeDeltaDistributionTest
 *
 * The form passes route through the same method, and they cannot satisfy it:
 * ArrayShape::validateForm() warms the cache and ::massageFormValues() then
 * lands in the cache-hit branch carrying raw submitted input, in which a media
 * child reads ['image' => ['open_button' => 'Add media', …]] — button labels
 * and option wrappers, structure rather than content, but indistinguishable
 * from content by inspection. Meanwhile the child itself is legitimately empty:
 * the author has turned "default" off and not yet chosen a media item. Every
 * "Add media" click on such a child inside an array raised an AssertionError,
 * which reached the editor as a 500 behind a Drupal.AjaxError.
 *
 * Nothing is dropped there — massageFormValues() overwrites the child's value
 * from the form on the very next statement — so the guard is scoped to shapes
 * built before any value was offered, which is the whole of the failure it
 * describes.
 *
 * The form pass is driven through ComponentPropValueHarvester, which is the
 * production caller. It used to be hand-rolled here, statement for statement,
 * because the sequence lived inline in a form's validate method and could not
 * be reached from a test — and a test that restates production code cannot
 * catch production code drifting away from it.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::getChildShapes()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape::massageFormValues()
 * @see \Drupal\neo_alchemist\ComponentPropValueHarvester::harvest()
 */
#[Group('neo_alchemist')]
class ChildrenShapeFormValueGuardTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Only the form pass below needs a host type and a privileged account;
    // the two direct-call tests would run without either.
    $this->installValueEditorHost();
  }

  /**
   * The delta under test.
   *
   * Its `provided` child is authored empty with "default" off — the state an
   * author is in between turning the default off and choosing a value, and the
   * only state in which the guard has anything to say.
   */
  private const DELTA = 0;

  /**
   * Submitted form values for the delta, shaped the way the form system is.
   *
   * `open_button` is the media widget's "Add media" button and `_options` is
   * this module's own option wrapper; both are form structure that arrives
   * looking exactly like content. Their presence is the point of the fixture:
   * it is what makes the offered value non-empty by every available test.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue::formAlter()
   */
  private const SUBMITTED = [
    self::DELTA => [
      '_weight' => '0',
      'title' => ['title' => [0 => ['value' => 'SUBMITTED TITLE']]],
      'provided' => [
        '_options' => ['default' => 0],
        'open_button' => 'Add media',
        'provided' => [0 => ['value' => '']],
      ],
    ],
  ];

  /**
   * Builds a component whose provider-owning child is authored empty.
   *
   * Config scope plus preview values is the lightest way to hand the `items`
   * array shape a delta-keyed override without standing up a host entity.
   *
   * @see \Drupal\Tests\neo_alchemist\Kernel\ChildrenShapeDeltaDistributionTest
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_provider')) {
      Component::create([
        'id' => 'na_array_provider',
        'label' => 'Array provider fixture',
        'description' => 'Array provider fixture',
        'component' => 'neo_alchemist_test:na_array_provider',
        'status' => TRUE,
        // Only the form pass needs a host type; the direct-call tests never
        // reach getEntity().
        'target_entity_type' => 'entity_test_with_bundle',
        'target_entity_bundle' => 'main',
      ])->save();
    }
    // Shape state is memoised per object, so never share one between passes.
    $storage->resetCache(['na_array_provider']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_provider');

    $component->setPreview(TRUE);
    $component->setPreviewValues([
      'props' => [
        'items' => [
          'ref' => 'array',
          'value' => [
            self::DELTA => [
              'title' => ['value' => 'AUTHORED TITLE'],
              // Empty, with the default turned off below: the child can neither
              // produce a value of its own nor fall back to the example.
              'provided' => ['value' => ''],
            ],
          ],
          'options' => [
            'items~title~' . self::DELTA => ['default' => 0],
            'items~provided~' . self::DELTA => ['default' => 0],
          ],
        ],
      ],
    ]);

    return $component;
  }

  /**
   * Returns the `items` array shape of a freshly built fixture component.
   */
  private function itemsShape(Component $component) {
    $shapes = $component->getPropShapes();
    $this->assertArrayHasKey('items', $shapes, 'The fixture exposes its array prop.');
    return $shapes['items'];
  }

  /**
   * Asserts the fixture actually reaches the branch the guard sits in.
   *
   * Without this the tests below could pass by never getting near the guard —
   * if the child stopped owning a providers-group plugin, or stopped being
   * empty, or had its default back on, the guard would be skipped and a
   * regression would sail through. Each is checked directly.
   */
  public function testFixtureReachesTheGuardedBranch(): void {
    $this->assertSame('1', ini_get('zend.assertions'), 'Assertions must be live for these tests to mean anything.');

    $shape = $this->itemsShape($this->buildComponent());
    $children = $shape->getChildShapes(self::DELTA);
    $this->assertArrayHasKey('provided', $children, 'The delta has the provider-owning child.');

    $child = $children['provided'];
    $this->assertNotEmpty(
      $child->getValueCollection()->getActiveInstances('providers'),
      'The child owns a providers-group plugin, so its parent refuses to push a value into it.',
    );
    $this->assertTrue($child->isEmpty(), 'The child holds no value of its own.');
    $this->assertFalse($child->getOptionDefault()->isEnabled(), 'The child has no default to fall back to.');
  }

  /**
   * Submitted form values reaching a warmed cache must not trip the guard.
   *
   * Reproduces the reported failure through the production caller rather than
   * around it: the harvest warms the per-delta shapes via
   * ArrayShape::validateForm() and massages them one statement later, which is
   * the ordering the guard has to tolerate. Before it was scoped, that second
   * call raised an AssertionError — the 500 behind the editor's AjaxError.
   */
  public function testSubmittedFormValuesDoNotTripTheGuard(): void {
    $component = $this->buildComponent();
    // Pin the premise the harvest depends on, since it now stands between this
    // test and the guard: the prop is offered, so the submission reaches it.
    $this->assertNotNull($this->itemsShape($component));

    $formState = new FormState();
    $formState->setUserInput([]);
    $form = ['#parents' => []];
    $panel = $this->container->get('neo_alchemist.value_panel_builder')
      ->build($component, $form, $formState);
    $form['values'] = $panel['values'];
    $this->assertArrayHasKey('items', $form['values'], 'The array prop has a form to submit against.');
    $formState->setValues(['values' => ['items' => self::SUBMITTED]]);

    $props = $this->container->get('neo_alchemist.prop_value_harvester')
      ->harvest($component, $form, $formState, []);

    // Reaching here at all is the assertion — an AssertionError would have
    // aborted the harvest. Stated explicitly so the test cannot be mistaken
    // for one that asserts nothing.
    $this->assertArrayHasKey('items', $props, 'The submission was harvested without raising an AssertionError.');
  }

  /**
   * The guard still fires when the shapes were built before the value.
   *
   * The counterpart, and the reason this is not simply a deleted assertion: a
   * scoping that silenced the guard everywhere — or a caller that goes back to
   * warming the per-delta shapes valueless — is caught here. Mirrors
   * getAllShapes(includeDeltas: TRUE), the render-path caller that produced the
   * original silent data loss.
   */
  public function testGuardStillFiresWhenShapesWereBuiltValueless(): void {
    $component = $this->buildComponent();
    $shape = $this->itemsShape($component);

    // No value offered: exactly ComponentShapePluginBase::getAllShapes().
    $shape->getChildShapes(self::DELTA);

    $this->expectException(\AssertionError::class);
    $this->expectExceptionMessage('was refused the value its parent offered');
    $shape->getChildShapes(self::DELTA, [
      'title' => ['value' => 'LATE TITLE'],
      'provided' => ['value' => 'LATE VALUE'],
    ]);
  }

}
