<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The round-trip and idempotence properties the architecture doc claims.
 *
 * ARCHITECTURE.md says the hybrid merge "is idempotent — stored subsets,
 * in-session merged values and stashed drafts all normalize to the same
 * result", and the strip/merge cycle is what an editing session runs on every
 * save. Nothing enforced either claim: the case-by-case tests pin particular
 * shapes, but the properties themselves — extract∘compose returns what it
 * started from, compose∘compose changes nothing — were only ever true by
 * inspection.
 *
 * These are the strongest tests available at this seam, and they are cheap now
 * that compose and extract are pure functions of three arrays.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::composeHybrid()
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::extractHybridStorage()
 */
#[Group('neo_alchemist')]
final class HybridRoundTripTest extends UnitTestCase {

  /**
   * The reserved root uuid.
   */
  private const ROOT = ComponentTreeStructure::ROOT_UUID;

  /**
   * A default layout with a flagged region and unrelated inherited structure.
   */
  private function defaults(): array {
    return [
      'tree' => [
        self::ROOT => [
          ['uuid' => 'header', 'component' => 'na_leaf'],
          ['uuid' => 'host', 'component' => 'na_region_host'],
          ['uuid' => 'footer', 'component' => 'na_leaf'],
        ],
        'host' => ['body' => [['uuid' => 'seed', 'component' => 'na_leaf']]],
      ],
      'props' => [
        'header' => ['text' => ['value' => 'HEADER']],
        'host' => [],
        'seed' => ['text' => ['value' => 'SEED']],
        'footer' => ['text' => ['value' => 'FOOTER']],
      ],
    ];
  }

  /**
   * The anchor flagging host.body.
   */
  private function anchors(): array {
    return ['host' => ['component' => 'na_region_host', 'slots' => ['body']]];
  }

  /**
   * The storage subsets an entity can legitimately hold.
   *
   * @return array
   *   Subsets keyed by the situation they represent.
   */
  private function subsets(): array {
    return [
      'authored content' => [
        'tree' => [
          self::ROOT => [],
          'host' => ['body' => [['uuid' => 'a1', 'component' => 'na_leaf']]],
        ],
        'props' => ['a1' => ['text' => ['value' => 'AUTHORED']]],
      ],
      'nested container' => [
        'tree' => [
          self::ROOT => [],
          'host' => ['body' => [['uuid' => 'box', 'component' => 'na_two_region']]],
          'box' => ['top' => [['uuid' => 'deep', 'component' => 'na_leaf']]],
        ],
        'props' => [
          'box' => [],
          'deep' => ['text' => ['value' => 'DEEP']],
        ],
      ],
      'explicitly emptied region' => [
        'tree' => [
          self::ROOT => [],
          'host' => ['body' => []],
        ],
        'props' => [],
      ],
    ];
  }

  /**
   * Extracting a merge of a subset returns the subset it started from.
   *
   * The save cycle: load composes the stored subset into a merged tree, and
   * preSave extracts a subset back out. A creator who opens a page and saves
   * it without touching anything must get their row back unchanged.
   */
  public function testExtractOfComposeReturnsTheStoredSubset(): void {
    foreach ($this->subsets() as $label => $subset) {
      $storedTree = $subset['tree'];
      unset($storedTree[self::ROOT]);

      $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $storedTree, $subset['props'], $this->anchors());
      $storage = ComponentTreeStructure::extractHybridStorage(
        $merged['tree'],
        $merged['props'],
        $this->anchors(),
        $merged['orphans'],
      );

      $this->assertEquals($subset['tree'], $storage['tree'], sprintf('Tree round-tripped: %s.', $label));
      $this->assertEquals($subset['props'], $storage['props'], sprintf('Props round-tripped: %s.', $label));
    }
  }

  /**
   * Composing a merged tree a second time changes nothing.
   *
   * The setter receives in-session merged values as well as stored subsets —
   * an editor commit, a stashed draft re-normalized against the current
   * default layout — and all of them must land on the same result.
   */
  public function testComposeIsIdempotent(): void {
    foreach ($this->subsets() as $label => $subset) {
      $storedTree = $subset['tree'];
      unset($storedTree[self::ROOT]);

      $once = ComponentTreeStructure::composeHybrid($this->defaults(), $storedTree, $subset['props'], $this->anchors());
      $again = ComponentTreeStructure::composeHybrid($this->defaults(), $once['tree'], $once['props'], $this->anchors());

      $this->assertEquals($once['tree'], $again['tree'], sprintf('Tree is stable under a second compose: %s.', $label));
      $this->assertEquals($once['props'], $again['props'], sprintf('Props are stable under a second compose: %s.', $label));
      $this->assertSame(['tree' => [], 'props' => []], $again['orphans'], sprintf('A merged tree reports no orphans: %s.', $label));
    }
  }

  /**
   * Extraction always leaves tree↔props parity satisfied.
   *
   * The invariant ComponentTreeItem::preSave() throws on. It is a
   * postcondition of extraction, so it holds for every subset shape rather
   * than only the ones a caller remembered to backfill.
   */
  public function testExtractionAlwaysSatisfiesParity(): void {
    foreach ($this->subsets() as $label => $subset) {
      $storedTree = $subset['tree'];
      unset($storedTree[self::ROOT]);

      $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $storedTree, $subset['props'], $this->anchors());
      $storage = ComponentTreeStructure::extractHybridStorage(
        $merged['tree'],
        $merged['props'],
        $this->anchors(),
        $merged['orphans'],
      );

      $placed = ComponentTreeStructure::collectInstanceUuids($storage['tree']);
      sort($placed);
      $stored = array_keys($storage['props']);
      sort($stored);
      $this->assertSame($placed, $stored, sprintf('Every instance has a props entry and no more: %s.', $label));
    }
  }

  /**
   * The merged tree never loses the inherited structure.
   *
   * Whatever a creator does inside a flagged region, the site builder's
   * header and footer stay exactly where the default layout puts them.
   */
  public function testInheritedStructureSurvivesEverySubset(): void {
    foreach ($this->subsets() as $label => $subset) {
      $storedTree = $subset['tree'];
      unset($storedTree[self::ROOT]);

      $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $storedTree, $subset['props'], $this->anchors());

      $this->assertSame(
        $this->defaults()['tree'][self::ROOT],
        $merged['tree'][self::ROOT],
        sprintf('The root section is the default layout: %s.', $label),
      );
      $this->assertSame(['text' => ['value' => 'HEADER']], $merged['props']['header'], sprintf('Inherited props are the default: %s.', $label));
    }
  }

  /**
   * A pristine entity's empty subset composes to the pure default.
   */
  public function testEmptySubsetComposesToTheDefault(): void {
    $merged = ComponentTreeStructure::composeHybrid($this->defaults(), [], [], $this->anchors());

    $this->assertSame($this->defaults()['tree'], $merged['tree']);
    $this->assertSame($this->defaults()['props'], $merged['props']);
  }

}
