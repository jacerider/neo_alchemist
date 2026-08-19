<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pure tree collectors underpinning hybrid field mode.
 *
 * Hybrid mode lets creators edit only the regions a site builder flagged with
 * `region_custom`, while the field's default layout stays authoritative for
 * everything else. These collectors decide which UUIDs belong to a creator and
 * which belong to the default — get that wrong and content is either dropped
 * on save or leaks between the two.
 *
 * Hybrid mode is live in production — node.project's field_full via
 * project_full, and taxonomy_term.market's level fields via hero_s2, all carry
 * region_custom flags — so these pins protect shipped data. The compose/extract
 * pair is covered in HybridStorageExtractionTest and HybridRoundTripTest, and
 * the Kernel Hybrid* suites cover the same behaviour end to end.
 *
 * These used to be reached through a test-only subclass of the field item list,
 * because the algebra lived on protected statics there. It lives on the tree
 * seam now and every caller reaches it exactly the way these tests do.
 */
#[Group('neo_alchemist')]
class HybridTreeAlgebraTest extends UnitTestCase {

  /**
   * A tree with one anchor slot, a nested child, and unrelated content.
   *
   * `owner` has two slots but only `region_a` is flagged. `inside` sits in the
   * flagged slot and itself contains `deep`. `outside` sits in the unflagged
   * slot and must never be treated as creator-owned.
   */
  private function tree(): array {
    return [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'owner', 'component' => 'container'],
      ],
      'owner' => [
        'region_a' => [['uuid' => 'inside', 'component' => 'card']],
        'region_b' => [['uuid' => 'outside', 'component' => 'card']],
      ],
      'inside' => [
        'content' => [['uuid' => 'deep', 'component' => 'cta']],
      ],
    ];
  }

  /**
   * The anchor set flagging only `region_a` on `owner`.
   */
  private function anchors(): array {
    return [
      'owner' => [
        'component' => 'container',
        'slots' => ['region_a'],
      ],
    ];
  }

  /**
   * The closure covers a flagged slot's contents and their descendants.
   */
  public function testClosureIncludesNestedDescendants(): void {
    $closure = ComponentTreeStructure::collectAnchorClosure($this->tree(), $this->anchors());

    $this->assertContains('inside', $closure);
    $this->assertContains('deep', $closure, 'Descendants of flagged content are creator-owned too.');
  }

  /**
   * The anchor owner itself is not part of its own closure.
   *
   * The owner belongs to the default layout — only what sits *inside* its
   * flagged slots is the creator's. Including it would let a save overwrite
   * the site builder's structure.
   */
  public function testClosureExcludesTheAnchorOwner(): void {
    $closure = ComponentTreeStructure::collectAnchorClosure($this->tree(), $this->anchors());

    $this->assertNotContains('owner', $closure);
  }

  /**
   * Content in a slot that was not flagged stays with the default layout.
   */
  public function testClosureExcludesUnflaggedSlots(): void {
    $closure = ComponentTreeStructure::collectAnchorClosure($this->tree(), $this->anchors());

    $this->assertNotContains('outside', $closure);
  }

  /**
   * No anchors means nothing is creator-owned.
   */
  public function testClosureIsEmptyWithoutAnchors(): void {
    $this->assertSame([], ComponentTreeStructure::collectAnchorClosure($this->tree(), []));
  }

  /**
   * An anchor pointing at a slot that no longer exists yields nothing.
   *
   * This is the "anchor postdates the stored value" case — the flagged slot
   * has not been populated yet.
   */
  public function testClosureToleratesMissingSlot(): void {
    $anchors = ['owner' => ['component' => 'container', 'slots' => ['nonexistent']]];

    $this->assertSame([], ComponentTreeStructure::collectAnchorClosure($this->tree(), $anchors));
  }

  /**
   * A cyclic tree terminates instead of looping forever.
   *
   * The closure walk is a work queue with a visited set; without the set a
   * cycle would hang the request rather than fail it.
   */
  public function testClosureTerminatesOnCyclicTree(): void {
    $tree = [
      'owner' => ['region_a' => [['uuid' => 'a', 'component' => 'x']]],
      'a' => ['content' => [['uuid' => 'b', 'component' => 'x']]],
      'b' => ['content' => [['uuid' => 'a', 'component' => 'x']]],
    ];
    $anchors = ['owner' => ['component' => 'container', 'slots' => ['region_a']]];

    $closure = ComponentTreeStructure::collectAnchorClosure($tree, $anchors);

    sort($closure);
    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Every referenced UUID is collected, except the root key itself.
   */
  public function testCollectUuidsGathersSectionsAndTuples(): void {
    $uuids = ComponentTreeStructure::collectUuids($this->tree());

    $this->assertNotContains(ComponentTreeStructure::ROOT_UUID, $uuids, 'The root key is a container, not a component.');
    foreach (['owner', 'inside', 'outside', 'deep'] as $expected) {
      $this->assertContains($expected, $uuids);
    }
  }

  /**
   * A UUID appearing as both a section key and a tuple is listed once.
   */
  public function testCollectUuidsDeduplicates(): void {
    $uuids = ComponentTreeStructure::collectUuids($this->tree());

    $this->assertSame(count($uuids), count(array_unique($uuids)));
  }

  /**
   * The instance collector excludes section-only keys.
   *
   * In a hybrid storage subset an anchor owner is a section key without being
   * a component instance of the subset itself, and inventing a props entry for
   * it would trip the save-time parity check.
   */
  public function testCollectInstanceUuidsExcludesSectionOnlyKeys(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [],
      'host' => ['body' => [['uuid' => 'container', 'component' => 'c']]],
      'container' => ['top' => [['uuid' => 'leaf', 'component' => 'l']]],
    ];

    $uuids = ComponentTreeStructure::collectInstanceUuids($tree);
    sort($uuids);

    $this->assertSame(['container', 'leaf'], $uuids, 'Only tuple uuids are returned — host is a section-only key.');
  }

  /**
   * Instances map to the component that renders them, in tree order.
   *
   * This is what custom-region anchor resolution reads to ask each component
   * which of its region props carry the region_custom flag.
   */
  public function testCollectInstancesMapsUuidsToComponents(): void {
    $this->assertSame(
      [
        'owner' => 'container',
        'inside' => 'card',
        'outside' => 'card',
        'deep' => 'cta',
      ],
      ComponentTreeStructure::collectInstances($this->tree()),
    );
  }

  /**
   * Component ids are collected once each, in first-seen order.
   */
  public function testCollectComponentIdsDeduplicates(): void {
    $this->assertSame(
      ['container', 'card', 'cta'],
      ComponentTreeStructure::collectComponentIds($this->tree()),
    );
  }

  /**
   * A tuple missing its uuid still contributes its component id.
   *
   * The usage report answers "is this component safe to delete?", so a
   * malformed placement must still count as a placement.
   */
  public function testCollectComponentIdsToleratesMissingUuid(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        ['component' => 'orphaned'],
        ['uuid' => 'a', 'component' => 'card'],
      ],
    ];

    $this->assertSame(['orphaned', 'card'], ComponentTreeStructure::collectComponentIds($tree));
    $this->assertSame(['a' => 'card'], ComponentTreeStructure::collectInstances($tree), 'A uuid-less tuple is not an instance.');
  }

  /**
   * A section's children are listed slot by slot.
   *
   * The clone recursion walks this rather than reaching into the raw array,
   * which is what keeps each cloned child in the slot it came from.
   */
  public function testCollectChildTuples(): void {
    $this->assertSame(
      [
        'region_a' => [['uuid' => 'inside', 'component' => 'card']],
        'region_b' => [['uuid' => 'outside', 'component' => 'card']],
      ],
      ComponentTreeStructure::collectChildTuples($this->tree(), 'owner'),
    );
    $this->assertSame([], ComponentTreeStructure::collectChildTuples($this->tree(), 'deep'), 'A leaf owns no section.');
  }

  /**
   * Stored item values decode from either JSON strings or arrays.
   *
   * The field item holds JSON, but in-memory values are arrays, and both reach
   * this helper depending on whether the entity was just loaded or just built.
   */
  public function testDecodeAcceptsJsonAndArrays(): void {
    $tree = [ComponentTreeStructure::ROOT_UUID => [['uuid' => 'a', 'component' => 'x']]];
    $props = ['a' => ['status' => 1]];

    [$fromJson, $propsFromJson] = ComponentTreeStructure::decodeValue([
      'tree' => json_encode($tree),
      'props' => json_encode($props),
    ]);
    $this->assertSame($tree, $fromJson);
    $this->assertSame($props, $propsFromJson);

    [$fromArray, $propsFromArray] = ComponentTreeStructure::decodeValue([
      'tree' => $tree,
      'props' => $props,
    ]);
    $this->assertSame($tree, $fromArray);
    $this->assertSame($props, $propsFromArray);
  }

  /**
   * Missing or malformed payloads decode to empty arrays, never NULL.
   *
   * Callers index straight into the result, so a NULL here would become a
   * TypeError somewhere far away from the actual cause.
   */
  public function testDecodeFallsBackToEmptyArrays(): void {
    foreach ([[], ['tree' => NULL, 'props' => NULL], ['tree' => 'not json', 'props' => '{'], ['tree' => 5]] as $input) {
      [$tree, $props] = ComponentTreeStructure::decodeValue($input);
      $this->assertSame([], $tree);
      $this->assertSame([], $props);
    }
  }

  /**
   * An empty root section marks a stored subset; a populated one does not.
   *
   * The normalization discriminant. It is a statement about tree shape, which
   * is why it lives on the seam rather than on the field list that branches on
   * it — and the Drush integrity command needs the same answer to pick an
   * empty-section policy per row.
   */
  public function testStorageSubsetDiscriminant(): void {
    $subset = [
      ComponentTreeStructure::ROOT_UUID => [],
      'host' => ['body' => [['uuid' => 'a', 'component' => 'x']]],
    ];
    $this->assertTrue(ComponentTreeStructure::isStorageSubset($subset));

    $merged = $this->tree();
    $this->assertFalse(ComponentTreeStructure::isStorageSubset($merged));
  }

}
