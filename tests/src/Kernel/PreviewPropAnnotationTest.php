<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Editor-preview prop annotation never reaches live markup.
 *
 * The single-component editor stamps `data-neo-prop="<shape id>"` on every
 * Attribute-carrying prop render value so the preview iframe can map its DOM
 * back to the form's fields (which carry the same id from
 * ComponentShapePluginBase::getForm()). The stamp is gated on
 * Component::isEditorPreview() — instance or component preview only. The
 * regression that matters is the gate leaking: an editor-only annotation in
 * production markup, or in the page-builder canvas whose overlay system has
 * its own vocabulary.
 *
 * The companion `data-neo-component` root stamp lives in toRenderable(),
 * which in preview mode stands up a placeholder target entity the minimal
 * module set has no entity type for — so it is not asserted here directly.
 * It sits behind the same isEditorPreview() gate this test pins, and the
 * live toRenderable() scenario proves the whole props tree is clean.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::buildRenderValue()
 * @see \Drupal\neo_alchemist\Entity\Component::isEditorPreview()
 */
#[Group('neo_alchemist')]
class PreviewPropAnnotationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`,
    // which the options module supplies.
    'options',
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
   * Instance preview stamps the shape id on Attribute render values.
   */
  public function testInstancePreviewStampsAttributeValues(): void {
    $component = $this->buildComponent(preview: TRUE, instancePreview: TRUE);
    $values = $component->getPropValues();

    $size = $values['heading']['size'] ?? NULL;
    $this->assertInstanceOf(Attribute::class, $size, 'Premise: the heading examples resolved and size is an attribute object.');
    $this->assertStringContainsString('data-neo-prop="heading~size"', (string) $size, 'The attribute value carries its shape id for the preview target index.');
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
