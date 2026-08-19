<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins expandClosure(), the one descendant walker on the tree seam.
 *
 * Four independent implementations of this walk used to exist: the structure's
 * own subtree set, the field list's tuple-closure expansion, the dependency-
 * detachment recursion and the field item's clone recursion. They were free to
 * disagree about cycles, junk seeds and missing sections; there is one now, and
 * these are its edge-case pins.
 */
#[Group('neo_alchemist')]
class ExpandClosureTest extends UnitTestCase {

  /**
   * Seeds are included and every nested descendant is collected.
   */
  public function testSeedsAndDescendantsCollected(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [],
      'a' => ['slot' => [['uuid' => 'b'], ['uuid' => 'c']]],
      'b' => ['inner' => [['uuid' => 'd']]],
    ];

    $closure = ComponentTreeStructure::expandClosure($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b', 'c', 'd'], $closure);
  }

  /**
   * Duplicate seeds are deduplicated.
   */
  public function testDuplicateSeedsDeduplicated(): void {
    $tree = ['a' => ['slot' => [['uuid' => 'b']]]];

    $closure = ComponentTreeStructure::expandClosure($tree, ['a', 'a', 'b']);
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

    $closure = ComponentTreeStructure::expandClosure($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Non-string seeds are skipped rather than fatal.
   */
  public function testNonStringSeedsSkipped(): void {
    $tree = ['a' => ['slot' => [['uuid' => 'b']]]];

    $closure = ComponentTreeStructure::expandClosure($tree, [NULL, 42, ['nested'], 'a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

  /**
   * Seeds without a section are still part of the closure.
   */
  public function testMissingSectionsTolerated(): void {
    $closure = ComponentTreeStructure::expandClosure([], ['ghost']);

    $this->assertSame(['ghost'], $closure);
  }

  /**
   * Malformed tuples without uuids contribute nothing.
   */
  public function testMalformedTuplesIgnored(): void {
    $tree = [
      'a' => ['slot' => [['component' => 'no_uuid'], [], ['uuid' => ''], ['uuid' => 'b']]],
    ];

    $closure = ComponentTreeStructure::expandClosure($tree, ['a']);
    sort($closure);

    $this->assertSame(['a', 'b'], $closure);
  }

}
