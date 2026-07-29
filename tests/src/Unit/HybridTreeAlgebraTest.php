<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pure tree helpers underpinning hybrid field mode.
 *
 * Hybrid mode lets creators edit only the regions a site builder flagged with
 * `region_custom`, while the field's default layout stays authoritative for
 * everything else. These four statics decide which UUIDs belong to a creator
 * and which belong to the default — get that wrong and content is either
 * dropped on save or leaks between the two.
 *
 * Hybrid mode is live in production — node.project's field_full via
 * project_full, and taxonomy_term.market's level fields via hero_s2, all
 * carry region_custom flags — so these pins protect shipped data. The
 * instance-level compose/extract pair is covered separately in
 * HybridStorageExtractionTest and the Kernel Hybrid* suites.
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
    $closure = TestNeoComponentTreeList::getSectionClosureUuids($this->tree(), $this->anchors());

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
    $closure = TestNeoComponentTreeList::getSectionClosureUuids($this->tree(), $this->anchors());

    $this->assertNotContains('owner', $closure);
  }

  /**
   * Content in a slot that was not flagged stays with the default layout.
   */
  public function testClosureExcludesUnflaggedSlots(): void {
    $closure = TestNeoComponentTreeList::getSectionClosureUuids($this->tree(), $this->anchors());

    $this->assertNotContains('outside', $closure);
  }

  /**
   * No anchors means nothing is creator-owned.
   */
  public function testClosureIsEmptyWithoutAnchors(): void {
    $this->assertSame([], TestNeoComponentTreeList::getSectionClosureUuids($this->tree(), []));
  }

  /**
   * An anchor pointing at a slot that no longer exists yields nothing.
   *
   * This is the "anchor postdates the stored value" case — the flagged slot
   * has not been populated yet.
   */
  public function testClosureToleratesMissingSlot(): void {
    $anchors = ['owner' => ['component' => 'container', 'slots' => ['nonexistent']]];

    $this->assertSame([], TestNeoComponentTreeList::getSectionClosureUuids($this->tree(), $anchors));
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

    $closure = TestNeoComponentTreeList::getSectionClosureUuids($tree, $anchors);

    sort($closure);
    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Every referenced UUID is collected, except the root key itself.
   */
  public function testGetTreeUuidsCollectsSectionsAndTuples(): void {
    $uuids = TestNeoComponentTreeList::getTreeUuidsPublic($this->tree());

    $this->assertNotContains(ComponentTreeStructure::ROOT_UUID, $uuids, 'The root key is a container, not a component.');
    foreach (['owner', 'inside', 'outside', 'deep'] as $expected) {
      $this->assertContains($expected, $uuids);
    }
  }

  /**
   * A UUID appearing as both a section key and a tuple is listed once.
   */
  public function testGetTreeUuidsDeduplicates(): void {
    $uuids = TestNeoComponentTreeList::getTreeUuidsPublic($this->tree());

    $this->assertSame(count($uuids), count(array_unique($uuids)));
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

    [$fromJson, $propsFromJson] = TestNeoComponentTreeList::decodeHybridItemValuePublic([
      'tree' => json_encode($tree),
      'props' => json_encode($props),
    ]);
    $this->assertSame($tree, $fromJson);
    $this->assertSame($props, $propsFromJson);

    [$fromArray, $propsFromArray] = TestNeoComponentTreeList::decodeHybridItemValuePublic([
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
      [$tree, $props] = TestNeoComponentTreeList::decodeHybridItemValuePublic($input);
      $this->assertSame([], $tree);
      $this->assertSame([], $props);
    }
  }

}
