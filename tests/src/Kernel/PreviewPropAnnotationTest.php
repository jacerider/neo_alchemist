<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\PreviewPropMapBuilder;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Editor-preview prop annotation never reaches live markup.
 *
 * The single-component editor stamps `data-neo-prop="<shape id>"` on
 * Attribute-carrying prop render values so the preview iframe can map its DOM
 * back to the form's fields (which carry the same id from
 * ComponentShapePluginBase::getForm()). Style shapes are excluded — see
 * testInstancePreviewDoesNotStampStyleValues() — so in practice the fixture
 * here, whose only Attribute value is the heading's `size`, carries no stamp
 * at all. The stamp is gated on Component::isEditorPreview() — instance or
 * component preview only. The regression that matters is the gate leaking: an
 * editor-only annotation in production markup, or in the page-builder canvas
 * whose overlay system has its own vocabulary.
 *
 * The companion `data-neo-component` root stamp lives in toRenderable(),
 * which in preview mode stands up a placeholder target entity the minimal
 * module set has no entity type for — so it is not asserted here directly.
 * It sits behind the same isEditorPreview() gate this test pins, and the
 * live toRenderable() scenario proves the whole props tree is clean.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::buildRenderValue()
 * @see \Drupal\neo_alchemist\Entity\Component::isEditorPreview()
 */
#[Group('neo_alchemist')]
class PreviewPropAnnotationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`,
    // which the options module supplies.
    'options',
    // na_array_in_array's inner rows are links, whose shape resolves the
    // `link` field item.
    'link',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * Builds the heading fixture in the requested preview flavor.
   *
   * Loaded fresh every time: shape state is memoised per entity object, so a
   * flavor comparison on a reused instance would resolve once and lie.
   */
  private function buildComponent(bool $preview = FALSE, bool $instancePreview = FALSE): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_heading')) {
      Component::create([
        'id' => 'na_heading',
        'label' => 'Heading fixture',
        'description' => 'Heading fixture',
        'component' => 'neo_alchemist_test:na_heading',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_heading']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_heading');
    $component->setPreview($preview);
    $component->setInstancePreview($instancePreview);
    return $component;
  }

  /**
   * No Attribute anywhere in a resolved value carries an editor annotation.
   */
  private function assertNoAnnotations(mixed $value, string $path): void {
    if ($value instanceof Attribute) {
      $rendered = (string) $value;
      $this->assertStringNotContainsString('data-neo-prop', $rendered, "Editor prop stamp leaked into non-editor markup at {$path}.");
      $this->assertStringNotContainsString('data-neo-component', $rendered, "Editor component stamp leaked into non-editor markup at {$path}.");
      return;
    }
    if (is_array($value)) {
      foreach ($value as $key => $item) {
        $this->assertNoAnnotations($item, "{$path}/{$key}");
      }
    }
  }

  /**
   * The gate: editor preview is instance preview, and nothing looser.
   *
   * The isComponentPreview() arm is route-derived (the two preview frame
   * routes) and has no route to match under kernel testing, so the gate is
   * exercised only through the instance flag here.
   */
  public function testEditorPreviewFlagGate(): void {
    $this->assertFalse($this->buildComponent()->isEditorPreview(), 'A live component is not an editor preview.');
    $canvas = $this->buildComponent(preview: TRUE);
    $this->assertTrue($canvas->isManagePreview(), 'Premise: preview without the instance flag is the page-builder canvas flavor.');
    $this->assertFalse($canvas->isEditorPreview(), 'The page-builder canvas is not an editor preview.');
    $this->assertTrue($this->buildComponent(preview: TRUE, instancePreview: TRUE)->isEditorPreview(), 'The single-component instance preview is an editor preview.');
  }

  /**
   * Style shapes are never stamped, even in the editor preview.
   *
   * A style attribute decorates an element rather than owning it, and Twig
   * prints it wherever the classes are needed — often a full-width content
   * wrapper carrying the reveal, which would then shadow every content prop
   * inside it. Chained merges make it worse: Attribute::merge() deep-merges
   * toArray() and this attribute is a scalar, so `animate.merge(animate_speed)`
   * would keep only the last id. Style DOM is mapped through
   * PreviewPropMapBuilder's content hints instead.
   */
  public function testInstancePreviewDoesNotStampStyleValues(): void {
    $component = $this->buildComponent(preview: TRUE, instancePreview: TRUE);
    $values = $component->getPropValues();

    $size = $values['heading']['size'] ?? NULL;
    $this->assertInstanceOf(Attribute::class, $size, 'Premise: the heading examples resolved and size is an attribute object.');
    $this->assertNoAnnotations($values, 'props');
  }

  /**
   * The prop map marks style shapes, which own no element to outline.
   *
   * The companion to the test above: a style shape is never stamped, so the
   * preview can find no element for it and, left to itself, would outline
   * nothing when one is focused. The flag is what lets it outline the
   * component such a prop restyles instead — the honest answer, since that is
   * the prop's actual scope.
   *
   * Built against empty props deliberately. The hints come from the resolved
   * values, but this flag comes from the shape, so an empty set pins it
   * without standing up a preview render — which needs a target entity type
   * the minimal module set here does not have.
   */
  public function testPropMapMarksStyleShapes(): void {
    // build() keeps only shapes the current user may update, and an anonymous
    // kernel user may update none — the map comes back empty and every
    // assertion below passes vacuously. Set up here rather than in setUp() so
    // the stamping tests keep running against the user they always have.
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], [], TRUE);

    $component = $this->buildComponent(preview: TRUE, instancePreview: TRUE);
    $map = PreviewPropMapBuilder::build($component, ['#props' => []]);
    $this->assertNotEmpty($map['props'], 'Premise: the map is populated, so the assertions below are not vacuous.');

    $this->assertArrayHasKey('heading~size', $map['props'], 'Premise: the style sub-prop reached the map.');
    $this->assertTrue($map['props']['heading~size']['style'], 'A style shape is marked so the preview outlines the component it restyles.');
    $this->assertArrayHasKey('heading~title', $map['props'], 'Premise: a content sub-prop reached the map.');
    $this->assertFalse($map['props']['heading~title']['style'], 'A content shape owns its own element and is not marked.');
  }

  /**
   * A value under two row indexes keeps the outer one, not just the inner.
   *
   * The builder used to carry a single delta, so descending into an array
   * inside a row overwrote the row it was inside: the second link of the first
   * group was filed under `groups~links~1`, which is the *second group's*
   * links prop. Nothing failed — the hint simply pointed at another row, and
   * clicking that link in the preview opened a field belonging to it.
   *
   * Asserted through the hints rather than the ids, because the ids were
   * always right; it was which value got filed against which id that was
   * wrong.
   */
  public function testPropMapKeepsOuterRowDeltaForNestedArrays(): void {
    // build() keeps only shapes the current user may update; see the note in
    // testPropMapMarksStyleShapes().
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], [], TRUE);

    $component = $this->buildNestedArrayComponent();
    $map = PreviewPropMapBuilder::build($component, $component->toRenderable());

    $filedUnder = [];
    foreach ($map['props'] as $id => $info) {
      foreach ($info['hints']['text'] ?? [] as $text) {
        $filedUnder[$text] = $id;
      }
    }
    $this->assertNotEmpty($filedUnder, 'Premise: hints were collected, so the assertions below are not vacuous.');

    $this->assertSame('groups~links~0~value~0', $filedUnder['GROUP 0 LINK 0'] ?? NULL);
    $this->assertSame('groups~links~0~value~1', $filedUnder['GROUP 0 LINK 1'] ?? NULL, 'The second link of the first group stays in the first group.');
    $this->assertSame('groups~links~1~value~0', $filedUnder['GROUP 1 LINK 0'] ?? NULL);
  }

  /**
   * Builds the array-inside-an-array fixture in the editor preview flavor.
   */
  private function buildNestedArrayComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_in_array')) {
      Component::create([
        'id' => 'na_array_in_array',
        'label' => 'Array in array fixture',
        'description' => 'Array in array fixture',
        'component' => 'neo_alchemist_test:na_array_in_array',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_array_in_array']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_in_array');
    $component->setPreview(TRUE);
    $component->setInstancePreview(TRUE);
    return $component;
  }

  /**
   * A live render carries no annotation anywhere in its props.
   */
  public function testLiveRenderIsClean(): void {
    $build = $this->buildComponent()->toRenderable();
    $this->assertNoAnnotations($build['#props'] ?? [], '#props');
  }

  /**
   * The page-builder canvas flavor resolves values without annotation.
   */
  public function testManageCanvasPreviewIsClean(): void {
    $values = $this->buildComponent(preview: TRUE)->getPropValues();

    $size = $values['heading']['size'] ?? NULL;
    $this->assertInstanceOf(Attribute::class, $size, 'Premise: the canvas flavor reached the same render-value branch the stamp lives on.');
    $this->assertNoAnnotations($values, 'props');
  }

}
