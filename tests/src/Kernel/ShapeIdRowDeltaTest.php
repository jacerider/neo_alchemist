<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * A row's delta reaches every shape beneath it, not just its own children.
 *
 * ComponentShapePluginBase::id() used to join the flat name path and append
 * only the shape's *own* delta. A shape two levels under an iterable holds
 * none — ObjectShape builds its children without one — so `items~heading~title`
 * was the same string for every row. Everything keyed by id collapsed with it:
 * getAllShapes() merges with `+=` and kept row 0 alone, prepForm() gave all
 * rows one previous_value slot, and the editor stamped one data-neo-prop on
 * every row's field, so hovering row 3 in the preview focused row 1's input.
 *
 * The id is now composed from the parent's, which puts each ancestor's delta
 * at its own depth — `items~heading~1~title`. That is the key
 * NestedOptionMap::childKey() has always written, so the read side agrees with
 * the write side, and it keeps a shape's id a prefix of its descendants',
 * which the preview's coarse-to-fine highlight depends on.
 *
 * The delta-free id — id(TRUE) — is deliberately untouched by all of this. It
 * is what addresses the config side (the `expression` string,
 * settings.props.*.plugins and .expanded, the prop form's tabs), so a delta
 * leaking into it would churn every component's config.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::id()
 * @see \Drupal\neo_alchemist\Shape\NestedOptionMap::childKey()
 */
#[Group('neo_alchemist')]
class ShapeIdRowDeltaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Component::create([
      'id' => 'na_array_nested',
      'label' => 'Array nested fixture',
      'description' => 'Array nested fixture',
      'component' => 'neo_alchemist_test:na_array_nested',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Loads the fixture fresh; shape state is memoised per entity object.
   */
  private function component(): Component {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('neo_component');
    $storage->resetCache(['na_array_nested']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_nested');
    return $component;
  }

  /**
   * Every row's sub-prop gets its own id, and the ids nest as prefixes.
   */
  public function testGrandchildIdsCarryTheirRowDelta(): void {
    $ids = array_keys($this->component()->getPropShapesAll(NULL, TRUE));

    $this->assertContains('items~heading~0', $ids, 'Premise: the fixture resolved two rows.');
    $this->assertContains('items~heading~1', $ids);

    $this->assertContains('items~heading~0~title', $ids, "A row's sub-prop is addressed under that row.");
    $this->assertContains('items~heading~1~title', $ids, 'The second row is not collapsed onto the first.');
    $this->assertNotContains('items~heading~title', $ids, 'No row-agnostic id survives to collide on.');

    // The preview highlights coarse-to-fine by prefix, so focusing the heading
    // must still reach the sub-props it owns.
    $this->assertStringStartsWith('items~heading~1~', 'items~heading~1~title', 'Premise of the prefix rule.');
    foreach (['items~heading~0~title', 'items~heading~1~title'] as $id) {
      $row = substr($id, 0, (int) strrpos($id, '~'));
      $this->assertContains($row, $ids, "The owning row {$row} is itself a shape, so the prefix walk lands on it.");
    }
  }

  /**
   * The delta-free id is unchanged — it is what the config side addresses.
   */
  public function testDeltaFreeIdIsRowAgnostic(): void {
    $shapes = $this->component()->getPropShapesAll(NULL, TRUE);

    $this->assertSame('items~heading~title', $shapes['items~heading~0~title']->id(TRUE));
    $this->assertSame('items~heading~title', $shapes['items~heading~1~title']->id(TRUE));
    $this->assertSame('items~heading', $shapes['items~heading~0']->id(TRUE));
    $this->assertSame('items', $shapes['items']->id(TRUE));
  }

  /**
   * The structural tree carries no deltas at all, so config keys do not move.
   */
  public function testStructuralTreeIsUnchanged(): void {
    $ids = array_keys($this->component()->getPropShapesAll(NULL, FALSE));

    $this->assertContains('items~heading~title', $ids, 'The delta-free tree still addresses sub-props without a row.');
    foreach ($ids as $id) {
      $this->assertDoesNotMatchRegularExpression('/~\d+(~|$)/', $id, "The structural id {$id} must carry no delta, or every component's saved config would churn.");
    }
  }

  /**
   * Labels name the row, or the editor cannot tell five fields apart.
   */
  public function testNestedTitlesNameTheRow(): void {
    $shapes = $this->component()->getPropShapesAll(NULL, TRUE);

    // The row index is one-based and decorated with a sibling's value, which
    // is picked by getDeltaLabel() from the row the delta belongs to — not
    // from the shape asking, which sits two levels down.
    $this->assertSame(
      'Items 1 "ROW LABEL 0": Heading: Title',
      $shapes['items~heading~0~title']->getNestedTitle(),
    );
    $this->assertSame(
      'Items 2 "ROW LABEL 1": Heading: Title',
      $shapes['items~heading~1~title']->getNestedTitle(),
    );
  }

  /**
   * Options saved before ids carried an ancestor's delta still resolve.
   *
   * They landed on the delta-free key, and there is no update path that could
   * rewrite them out of every node's props column — so initOptions() reads
   * that key as a fallback whenever a delta is in play, giving each row the
   * value it had when they shared one.
   */
  public function testLegacyDeltaFreeOptionsStillApply(): void {
    $component = $this->component();
    $items = $component->getPropShape('items');
    $this->assertNotNull($items, 'Premise: the fixture exposes an `items` prop.');

    // Seeded the way a pre-change save would have: keyed without any delta.
    $items->getNestedOptionMap()->merge([
      'items~heading~title' => ['access' => TRUE],
    ]);

    $shapes = $component->getPropShapesAll(NULL, TRUE);
    foreach (['items~heading~0~title', 'items~heading~1~title'] as $id) {
      $this->assertSame(
        ['access' => TRUE],
        $shapes[$id]->getOptions($shapes[$id]->id(TRUE)),
        "The legacy key is still readable for {$id}.",
      );
    }
  }

}
