<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which option controls a shape's form offers, and which it lets you reach.
 *
 * `empty` and `default` are alternatives to an authored value, so each one's
 * control is withdrawn while the other state holds. They used to be coupled a
 * second way as well: a provider force-showing one option's form suppressed
 * the OTHER option's control outright. MediaValue force-shows `default` for
 * every media shape in every scope, so an image prop a site builder had
 * configured hidden reached the editor with no way to say `Show`, and with a
 * legend that could not say `(Hidden)` either, because that state is pushed
 * from inside the branch that was never taken.
 *
 * The rule now: you can always get out of a state you are in, and you can
 * enter the other one while nothing else is already suppressing the value.
 *
 * Asserted against the built form rather than through a media prop, so that
 * the rule is stated once instead of once per provider that forces a form.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getForm()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue::onShapeInit()
 */
#[Group('neo_alchemist')]
class ShapeOptionControlOfferTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installValueEditorHost();
  }

  /**
   * Builds the leaf fixture, optionally with options its component configured.
   *
   * @param array $options
   *   The options to configure for the `text` prop, keyed by shape id.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component, with its shapes freshly built.
   */
  private function component(array $options = []): ComponentInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Option control fixture',
      'description' => 'Option control fixture',
      'component' => self::LEAF_SDC,
      'status' => TRUE,
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    $id = $component->id();
    if ($options) {
      $this->container->get('config.factory')
        ->getEditable('neo_alchemist.neo_component.' . $id)
        ->set('settings.props.text.plugins.text.default', [
          'id' => 'default',
          'settings' => [
            'field_type' => 'map',
            'default' => NULL,
            'options' => $options,
          ],
        ])
        ->save();
    }
    // Shape state is memoised per object, so never share one between passes.
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\ComponentInterface $component */
    $component = $storage->load($id);
    $component->setPreview(TRUE);
    $this->resetPreviewValues($component);
    return $component;
  }

  /**
   * Builds the value panel exactly as an editor does.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being edited.
   *
   * @return array
   *   The built `text` prop element.
   */
  private function buildTextProp(ComponentInterface $component): array {
    $formState = new FormState();
    $formState->setUserInput([]);
    $form = ['#parents' => []];
    $panel = $this->container->get('neo_alchemist.value_panel_builder')
      ->build($component, $form, $formState);
    return $panel['values']['text'];
  }

  /**
   * A prop with nothing forced offers both controls, each one reachable.
   *
   * The baseline the cases below are departures from. Without it they could
   * pass against a form that offers no controls at all.
   */
  public function testAnUnforcedPropOffersBothControls(): void {
    $element = $this->buildTextProp($this->component());

    $this->assertTrue($element['_options']['empty']['#access']);
    $this->assertTrue($element['_options']['default']['#access']);
  }

  /**
   * A forced `default` no longer suppresses the `Hide` control.
   *
   * The regression test for the reported bug, with `default` force-shown the
   * way MediaValue::onShapeInit() force-shows it for every media shape. Red
   * before the fix with no `empty` element built at all, which is why a
   * background image configured hidden offered no way to show it.
   */
  public function testForcedDefaultNoLongerSuppressesTheHideControl(): void {
    $component = $this->component(['text' => ['empty' => 1]]);
    $shape = $component->getPropShapes()['text'];
    $shape->getOptionDefault()->alwaysShowForm(TRUE, 'As MediaValue does.');
    $this->assertTrue($shape->getOptionEmpty()->isEnabled(), 'Premise: hidden.');

    $element = $this->buildTextProp($component);

    $this->assertArrayHasKey(
      'empty',
      $element['_options'],
      'The control is built even though the other option forced its form.',
    );
    $this->assertTrue(
      $element['_options']['empty']['#access'],
      'And it is reachable, because there is always a way out of a state.',
    );
    $this->assertSame('Show', (string) $element['_options']['empty']['#title']);
  }

  /**
   * The legend reports a hidden prop as hidden.
   *
   * The state is pushed from inside the same branch that builds the control,
   * so a suppressed control took the legend's `(Hidden)` down with it and the
   * row read `(Default)` instead: the one place an editor could have noticed
   * the prop was hidden at all.
   */
  public function testLegendReportsTheHiddenState(): void {
    $component = $this->component(['text' => ['empty' => 1]]);
    $component->getPropShapes()['text']
      ->getOptionDefault()->alwaysShowForm(TRUE, 'As MediaValue does.');

    $element = $this->buildTextProp($component);

    $this->assertStringContainsString(
      'Hidden',
      (string) $element['#title'],
      'The legend names the state the prop is actually in.',
    );
  }

  /**
   * The `Hide` control withdraws while the prop sits on its default.
   *
   * The other half of the rule, and the reason the fix is a widening rather
   * than a removal: hiding a prop that is already showing its default value
   * is not a state the editor has any way to read back, so the way in is
   * withheld until the value is the author's own.
   */
  public function testHideControlWithdrawsWhileSittingOnTheDefault(): void {
    $component = $this->component(['text' => ['default' => 1]]);
    $shape = $component->getPropShapes()['text'];
    $this->assertTrue($shape->getOptionDefault()->isEnabled(), 'Premise.');
    $this->assertFalse($shape->getOptionEmpty()->isEnabled(), 'Premise.');

    $element = $this->buildTextProp($component);

    $this->assertFalse($element['_options']['empty']['#access']);
    $this->assertTrue(
      $element['_options']['default']['#access'],
      'The way back out of the default stays open.',
    );
  }

}
