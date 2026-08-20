<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist_test\Plugin\ComponentShape\TestStampedShape;
use PHPUnit\Framework\Attributes\Group;

/**
 * Render attributes reach a shape by being passed to it.
 *
 * Render mode used to be a hidden flag. ::getPropValue() stashed the attributes
 * on `$this`, while the predicate deciding whether to run the pre-render stage
 * read them off the *root* shape — so the two halves only met when the shape
 * they were called on happened to be a root. On a child shape the setter wrote
 * a flag nobody read: the pre-render stage was skipped, the un-rendered value
 * came back, and nothing said so.
 *
 * The attributes are now an argument threaded down the value-building chain,
 * which is what this pins. ::getPropValue() renders whatever shape it is called
 * on, ::getValue() still does not, and a container hands its children the same
 * object it was given rather than a copy.
 *
 * Red/green proof performed during development.
 * testNonRootPropValueRunsTheRenderPass is red against the pre-fix code, with
 * the un-rendered string as the failure. The two threading cases are
 * mutation-proven instead, because the flag they replace made them pass before:
 * dropping the pass-through in ObjectShape::buildValue()
 * (`getValue($renderAttributes)` → `getValue()`) turns both red, and only
 * the stamped child catches the copy — a container passing a *clone* still
 * yields a Markup value and would satisfy every other assertion here.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::getValue()
 * @see \Drupal\neo_alchemist\ComponentShapeRenderInterface::getPropValue()
 */
#[Group('neo_alchemist')]
class ShapeRenderAttributeThreadTest extends KernelTestBase {

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
   * The authored string, chosen because the render pass shows in its type.
   *
   * StringShape::preRenderValue() wraps a string carrying markup in a Markup
   * object; anything without tags passes through unchanged and would leave the
   * two entry points indistinguishable.
   */
  private const AUTHORED = '<em>Authored</em>';

  /**
   * Builds the fixture with an authored markup string in an object child.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_render_thread')) {
      Component::create([
        'id' => 'na_render_thread',
        'label' => 'Render thread fixture',
        'description' => 'Render thread fixture',
        'component' => 'neo_alchemist_test:na_render_thread',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_render_thread']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_render_thread');

    $component->setPreview(TRUE);
    $component->setPreviewValues([
      'props' => [
        'box' => [
          'ref' => 'object',
          'value' => [
            'text' => ['value' => self::AUTHORED],
            'stamp' => ['value' => 'stamped'],
          ],
          'options' => [
            'box~text' => ['default' => 0],
            'box~stamp' => ['default' => 0],
          ],
        ],
      ],
    ]);

    return $component;
  }

  /**
   * Returns the object prop's `text` child, warmed the way a render warms it.
   *
   * Children are built and memoised from the parent's value, so the parent has
   * to resolve once before a child holds anything. That is what a render does,
   * and doing it here leaves the shape under test in the state the pipeline
   * actually hands it over in.
   */
  private function childShape(Component $component): ComponentShapePluginInterface {
    $box = $component->getPropShapes()['box'];
    $this->assertInstanceOf(ComponentShapeChildrenMatchPluginInterface::class, $box);
    $box->getPropValue(new Attribute());
    $text = $box->getChildShapes()['text'];
    $this->assertFalse($text->isRoot(), 'The shape under test has to be a non-root one for this test to mean anything.');
    return $text;
  }

  /**
   * Asking a non-root shape for its prop value runs the pre-render stage.
   *
   * This is the regression. Before the attributes were threaded, this call set
   * a flag on the child while the predicate read the root's, so it returned the
   * plain resolved value and the caller had no way to tell.
   */
  public function testNonRootPropValueRunsTheRenderPass(): void {
    $value = $this->childShape($this->buildComponent())->getPropValue(new Attribute());

    $this->assertInstanceOf(
      MarkupInterface::class,
      $value,
      'Asking a non-root shape for its prop value must render it, not quietly hand back the un-rendered value.',
    );
    $this->assertSame(self::AUTHORED, (string) $value);
  }

  /**
   * Asking the same shape for its value still skips the render pass.
   *
   * The pair is the point: one entry point resolves, the other renders, and
   * which one was called is now the only thing that decides.
   */
  public function testValueGetterStillSkipsTheRenderPass(): void {
    $value = $this->childShape($this->buildComponent())->getValue();

    $this->assertIsString($value);
    $this->assertNotInstanceOf(MarkupInterface::class, $value, 'getValue() resolves; it does not render.');
    $this->assertSame(self::AUTHORED, $value);
  }

  /**
   * A container hands its children the attributes it was given, not a copy.
   *
   * Threading only matches the old root-held flag while the object reaching a
   * nested shape is the one the component builds its wrapper from — a style
   * child merging its classes into a copy would render nothing on the wrapper
   * and still satisfy every assertion about values. The fixture's stamped child
   * signs whatever it is handed, so the signature landing here is the proof.
   */
  public function testContainerThreadsTheSameAttributesToItsChildren(): void {
    $attributes = new Attribute();
    $this->buildComponent()->getPropShapes()['box']->getPropValue($attributes);

    $this->assertTrue(
      isset($attributes[TestStampedShape::STAMP]),
      'The nested shape rendered against attributes the container never handed it.',
    );
    $this->assertSame('box~stamp', (string) $attributes[TestStampedShape::STAMP]);
  }

  /**
   * The whole component still reaches its nested shapes the same way.
   *
   * The entry point a real render uses is Component::getPropValues(), which
   * makes the attributes object itself. Pinned separately from the call above
   * so the threading cannot be proven only on the path a test drives by hand.
   */
  public function testComponentPropValuesReachTheNestedShape(): void {
    $values = $this->buildComponent()->getPropValues();

    $this->assertInstanceOf(MarkupInterface::class, $values['box']['text'] ?? NULL);
    $this->assertTrue(
      isset($values['attributes'][TestStampedShape::STAMP]),
      'A nested shape did not render during a full component build.',
    );
  }

}
