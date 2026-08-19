<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\NestedOptionMap;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the store behind a shape's nested options.
 *
 * This store used to be two protected arrays on the shape base behind fourteen
 * interface methods — get/set × default/not × three option names — so nothing
 * about it could be asserted without a component, a producer and a container.
 * The properties pinned here are the ones the wrappers disagreed about: which
 * key a caller writes, which layer a reader sees, and what the union between
 * the two layers actually discards.
 *
 * @see \Drupal\neo_alchemist\NestedOptionMap
 */
#[Group('neo_alchemist')]
class NestedOptionMapTest extends UnitTestCase {

  /**
   * A relative child name is stored under the owner's chained id.
   *
   * The key format is the one ComponentShapePluginBase::id() builds, and this
   * is the only place that builds it. Two callers naming the same child can no
   * longer produce two different keys.
   */
  public function testChildIsKeyedUnderItsOwner(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->set('title', NestedOptionMap::OPTION_DEFAULT);

    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE]],
      $options->toArray(),
    );
  }

  /**
   * What a caller sets, the same caller reads back.
   */
  public function testSetIsReadBackByGet(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->set('title', NestedOptionMap::OPTION_ACCESS, FALSE);

    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_ACCESS));
    $this->assertTrue($options->set('title', NestedOptionMap::OPTION_ACCESS)->get('title', NestedOptionMap::OPTION_ACCESS));
  }

  /**
   * An option nobody set reads FALSE.
   */
  public function testAnUnsetOptionIsFalse(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_DEFAULT));
  }

  /**
   * Saved config arrives as ints, and 0 means off.
   *
   * ComponentShapePluginBase::validateForm() casts the checkboxes to int before
   * storing them, so everything that comes back from config is 0 or 1 rather
   * than a bool. Reading them as truthiness rather than identity is what makes
   * a stored 0 mean what the site builder ticked.
   */
  public function testStoredIntegersReadAsBooleans(): void {
    $options = (new NestedOptionMap())->forShape('heading');
    $options->merge([
      'heading~title' => [
        NestedOptionMap::OPTION_DEFAULT => 1,
        NestedOptionMap::OPTION_EMPTY => 0,
      ],
    ]);

    $this->assertTrue($options->get('title', NestedOptionMap::OPTION_DEFAULT));
    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_EMPTY));
  }

  /**
   * Reads see the saved layer and not the fallback one.
   *
   * The asymmetry is deliberate and predates the map: the getter behind the old
   * wrappers read `nestedOptions` alone, while every array-shaped read unions
   * the two layers. HeadingValue::onShapeInit() depends on it — it reads back
   * `default` on the title to decide whether the anchor sub-prop follows, and
   * must see only what the not-editable branch wrote, not what a source seeded
   * as a fallback.
   */
  public function testGetReadsTheSavedLayerOnly(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->setFallback('title', NestedOptionMap::OPTION_DEFAULT);

    $this->assertFalse($options->get('title', NestedOptionMap::OPTION_DEFAULT));
    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE]],
      $options->toArray(),
      'The fallback is still visible to an array-shaped read.',
    );
  }

  /**
   * One saved option discards the whole fallback entry for that child.
   *
   * The two layers union by top-level key rather than merging, so writing any
   * single option for a child drops every fallback that child had. This is the
   * reason a heading with `title_edit: false` never showed its hidden default,
   * and the reason the sub-prop source bug stayed invisible for so long. Pinned
   * here so that changing the union to a deep merge — a separate, module-wide
   * decision — cannot happen by accident.
   */
  public function testSavedOptionShadowsTheWholeFallbackEntry(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->setFallback('title', NestedOptionMap::OPTION_EMPTY);
    $options->set('title', NestedOptionMap::OPTION_DEFAULT);

    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE]],
      $options->toArray(),
    );
  }

  /**
   * A fallback for one child survives a saved option on another.
   *
   * The shadowing above is per top-level key. If it reached further than that
   * the union would be a bug rather than a documented sharp edge.
   */
  public function testShadowingIsPerChild(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->setFallback('supertitle', NestedOptionMap::OPTION_EMPTY);
    $options->set('title', NestedOptionMap::OPTION_DEFAULT);

    $this->assertSame(
      [
        'heading~title' => [NestedOptionMap::OPTION_DEFAULT => TRUE],
        'heading~supertitle' => [NestedOptionMap::OPTION_EMPTY => TRUE],
      ],
      $options->toArray(),
    );
  }

  /**
   * Every view writes into one store.
   *
   * A child shape reaches the map through its own view, and the options a
   * parent set for it have to be the options it reads for itself. The old
   * arrangement achieved that by making seven accessors delegate to the root
   * shape; here the delegation is the store reference and is written once.
   */
  public function testViewsOfOneMapShareOneStore(): void {
    $map = new NestedOptionMap();

    $map->forShape('heading')->set('title', NestedOptionMap::OPTION_DEFAULT);

    $this->assertSame(
      [NestedOptionMap::OPTION_DEFAULT => TRUE],
      $map->forShape('heading~title')->getOwn(),
      'The child reads under its own id what its parent wrote under a relative name.',
    );
    $this->assertTrue(
      $map->forShape('heading')->get('title', NestedOptionMap::OPTION_DEFAULT),
    );
  }

  /**
   * Re-scoping a view keeps the same store rather than starting a new one.
   */
  public function testRescopingKeepsTheStore(): void {
    $heading = (new NestedOptionMap())->forShape('heading');

    $heading->forShape('other')->set('title', NestedOptionMap::OPTION_EMPTY);

    $this->assertSame(
      ['other~title' => [NestedOptionMap::OPTION_EMPTY => TRUE]],
      $heading->toArray(),
    );
  }

  /**
   * A shape's own options are keyed by its bare id, with no child segment.
   */
  public function testOwnOptionsAreKeyedByTheBareId(): void {
    $map = new NestedOptionMap();

    $map->forShape('heading')->replaceOwn([NestedOptionMap::OPTION_DEFAULT => 1]);

    $this->assertSame(['heading' => [NestedOptionMap::OPTION_DEFAULT => 1]], $map->toArray());
    $this->assertSame([NestedOptionMap::OPTION_DEFAULT => 1], $map->forShape('heading')->getOwn());
  }

  /**
   * Replacing a shape's own options discards what was there.
   *
   * It is the sink for a submitted form's `_options`, which carries every
   * checkbox the shape offered. An option the site builder cleared is absent
   * from neither — it arrives as 0 — so a merge here would only serve to keep
   * options no form can clear.
   */
  public function testReplaceOwnDiscardsWhatWasThereBefore(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->replaceOwn([NestedOptionMap::OPTION_DEFAULT => 1, NestedOptionMap::OPTION_EMPTY => 1]);
    $options->replaceOwn([NestedOptionMap::OPTION_DEFAULT => 0]);

    $this->assertSame([NestedOptionMap::OPTION_DEFAULT => 0], $options->getOwn());
  }

  /**
   * A shape with nothing recorded for it has no own options.
   */
  public function testShapeWithNothingRecordedHasNoOwnOptions(): void {
    $this->assertSame([], (new NestedOptionMap())->forShape('heading')->getOwn());
  }

  /**
   * Merging adds to what is there; it never overwrites it.
   *
   * Both merge methods are how stored settings and a provider's configuration
   * enter the map, and either can run more than once against the same shape.
   * First write wins, per option rather than per child.
   */
  public function testMergeKeepsWhatIsAlreadySet(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->set('title', NestedOptionMap::OPTION_DEFAULT, FALSE);
    $options->merge([
      'heading~title' => [
        NestedOptionMap::OPTION_DEFAULT => TRUE,
        NestedOptionMap::OPTION_EMPTY => TRUE,
      ],
    ]);

    $this->assertSame(
      [
        'heading~title' => [
          NestedOptionMap::OPTION_DEFAULT => FALSE,
          NestedOptionMap::OPTION_EMPTY => TRUE,
        ],
      ],
      $options->toArray(),
    );
  }

  /**
   * The fallback layer merges the same way, and stays a fallback.
   */
  public function testMergeDefaultsKeepsWhatIsAlreadySetInTheFallbackLayer(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $options->setFallback('title', NestedOptionMap::OPTION_EMPTY, FALSE);
    $options->mergeFallbacks([
      'heading~title' => [
        NestedOptionMap::OPTION_EMPTY => TRUE,
        NestedOptionMap::OPTION_DEFAULT => TRUE,
      ],
    ]);

    $this->assertSame(
      [
        'heading~title' => [
          NestedOptionMap::OPTION_EMPTY => FALSE,
          NestedOptionMap::OPTION_DEFAULT => TRUE,
        ],
      ],
      $options->toArray(),
    );
    $this->assertFalse(
      $options->get('title', NestedOptionMap::OPTION_DEFAULT),
      'A merged fallback is still invisible to ::get().',
    );
  }

  /**
   * A subtree is the shape's own entry plus its descendants.
   *
   * DefaultValue narrows the map to one shape before storing it, so that a
   * provider's configuration carries its own options and not the whole
   * component's. It used to re-derive the key format to do that.
   */
  public function testSubtreeIsTheShapeAndItsDescendants(): void {
    $map = new NestedOptionMap();
    $map->forShape('heading')->replaceOwn([NestedOptionMap::OPTION_DEFAULT => 1]);
    $map->forShape('heading')->set('title', NestedOptionMap::OPTION_EMPTY);
    $map->forShape('heading~title')->set('link', NestedOptionMap::OPTION_ACCESS, FALSE);
    $map->forShape('other')->set('title', NestedOptionMap::OPTION_EMPTY);

    $this->assertSame(
      [
        'heading' => [NestedOptionMap::OPTION_DEFAULT => 1],
        'heading~title' => [NestedOptionMap::OPTION_EMPTY => TRUE],
        'heading~title~link' => [NestedOptionMap::OPTION_ACCESS => FALSE],
      ],
      $map->forShape('heading')->subtree(),
    );
  }

  /**
   * A sibling whose id merely starts the same way is not in the subtree.
   *
   * `heading2` is not a descendant of `heading`; only the separator makes one.
   * A prefix comparison that forgets it hands one provider another's options.
   */
  public function testSubtreeExcludesSiblingSharingPrefix(): void {
    $map = new NestedOptionMap();
    $map->forShape('heading')->set('title', NestedOptionMap::OPTION_EMPTY);
    $map->forShape('heading2')->set('title', NestedOptionMap::OPTION_EMPTY);

    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_EMPTY => TRUE]],
      $map->forShape('heading')->subtree(),
    );
  }

  /**
   * A subtree sees the fallback layer, because a stored subtree must carry it.
   */
  public function testSubtreeIncludesTheFallbackLayer(): void {
    $map = new NestedOptionMap();
    $map->forShape('heading')->setFallback('title', NestedOptionMap::OPTION_EMPTY);

    $this->assertSame(
      ['heading~title' => [NestedOptionMap::OPTION_EMPTY => TRUE]],
      $map->forShape('heading')->subtree(),
    );
  }

  /**
   * The key a child is recorded under is available to callers without the map.
   *
   * ArrayShape rewrites its children's options into their new deltas while
   * massaging raw submitted values, before those children exist as shapes. It
   * used to join the segments itself, which made it the second place that knew
   * what a child key looks like.
   */
  public function testChildKeyIsAskedForRatherThanRebuilt(): void {
    $options = (new NestedOptionMap())->forShape('gallery');

    $this->assertSame('gallery~image', $options->childKey('image'));
    $this->assertSame('gallery~image~2', $options->childKey('image', 2));
    $this->assertSame(
      'gallery~image~2',
      $options->childKey('image', '2'),
      'A delta arriving from a form as a string keys the same entry as an int.',
    );
  }

  /**
   * The key a caller is handed is the key a write lands on.
   */
  public function testChildKeyAgreesWithWhatSetWrites(): void {
    $options = (new NestedOptionMap())->forShape('gallery');

    $options->set('image', NestedOptionMap::OPTION_EMPTY);

    $this->assertArrayHasKey($options->childKey('image'), $options->toArray());
  }

  /**
   * The setters chain.
   */
  public function testTheSettersChain(): void {
    $options = (new NestedOptionMap())->forShape('heading');

    $returned = $options
      ->set('title', NestedOptionMap::OPTION_DEFAULT)
      ->setFallback('title', NestedOptionMap::OPTION_EMPTY)
      ->merge([])
      ->mergeFallbacks([])
      ->replaceOwn([]);

    $this->assertSame($options, $returned);
  }

}
