<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins expandTupleClosure(), the descendant walker behind hybrid ownership.
 *
 * The public getSectionClosureUuids() (covered by HybridTreeAlgebraTest)
 * delegates the actual graph walk to this helper, exposed on the test subclass
 * but never directly exercised. Everything hybrid ultimately keys ownership
 * off this closure, so its edge behavior — cycles, junk seeds, missing
 * sections — deserves its own pins.
 */
#[Group('neo_alchemist')]
class ExpandTupleClosureTest extends UnitTestCase {

  /**
   * Seeds are included and every nested descendant is collected.
   */
  public function testSeedsAndDescendantsCollected(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [],
      'a' => ['slot' => [['uuid' => 'b'], ['uuid' => 'c']]],
      'b' => ['inner' => [['uuid' => 'd']]],
    ];

    $closure = TestNeoComponentTreeList::expandTupleClosurePublic($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b', 'c', 'd'], $closure);
  }

  /**
   * Duplicate seeds are deduplicated.
   */
  public function testDuplicateSeedsDeduplicated(): void {
    $tree = ['a' => ['slot' => [['uuid' => 'b']]]];

    $closure = TestNeoComponentTreeList::expandTupleClosurePublic($tree, ['a', 'a', 'b']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * A cyclic tree terminates instead of looping forever.
   */
  public function testCyclicTreeTerminates(): void {
    $tree = [
      'a' => ['slot' => [['uuid' => 'b']]],
      'b' => ['slot' => [['uuid' => 'a']]],
    ];

    $closure = TestNeoComponentTreeList::expandTupleClosurePublic($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Non-string seeds are skipped rather than fatal.
   */
  public function testNonStringSeedsSkipped(): void {
    $tree = ['a' => ['slot' => [['uuid' => 'b']]]];

    $closure = TestNeoComponentTreeList::expandTupleClosurePublic($tree, [NULL, 42, ['nested'], 'a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Seeds without a section are still part of the closure.
   */
  public function testMissingSectionsTolerated(): void {
    $closure = TestNeoComponentTreeList::expandTupleClosurePublic([], ['ghost']);

    $this->assertSame(['ghost'], $closure);
  }

  /**
   * Malformed tuples without uuids contribute nothing.
   */
  public function testMalformedTuplesIgnored(): void {
    $tree = [
      'a' => ['slot' => [['component' => 'no_uuid'], [], ['uuid' => ''], ['uuid' => 'b']]],
    ];

    $closure = TestNeoComponentTreeList::expandTupleClosurePublic($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

}
