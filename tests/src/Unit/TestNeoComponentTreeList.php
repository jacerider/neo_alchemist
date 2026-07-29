<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;

/**
 * Exposes NeoComponentTreeList's protected static helpers for unit testing.
 *
 * The hybrid tree helpers are pure array/JSON functions with no dependency on
 * the field item list they live on, so they can be exercised without standing
 * up the Field API. Only `getSectionClosureUuids()` is already public; the rest
 * are protected, hence these thin pass-throughs.
 *
 * Subclassing rather than using reflection keeps the call sites readable and
 * means a signature change breaks the test at the seam rather than at runtime.
 */
final class TestNeoComponentTreeList extends NeoComponentTreeList {

  /**
   * Exposes ::getTreeUuids().
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   All section keys and tuple UUIDs, excluding the root key.
   */
  public static function getTreeUuidsPublic(array $tree): array {
    return static::getTreeUuids($tree);
  }

  /**
   * Exposes ::expandTupleClosure().
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $uuids
   *   The seed UUIDs.
   *
   * @return string[]
   *   The seed UUIDs plus every descendant found in the tree.
   */
  public static function expandTupleClosurePublic(array $tree, array $uuids): array {
    return static::expandTupleClosure($tree, $uuids);
  }

  /**
   * Exposes ::decodeHybridItemValue().
   *
   * @param array $itemValue
   *   The raw field item value.
   *
   * @return array
   *   A tuple of [tree, props], both guaranteed to be arrays.
   */
  public static function decodeHybridItemValuePublic(array $itemValue): array {
    return static::decodeHybridItemValue($itemValue);
  }

}
