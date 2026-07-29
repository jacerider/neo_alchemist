<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;

/**
 * Exposes NeoComponentTreeList's hybrid compose/extract for unit testing.
 *
 * Unlike TestNeoComponentTreeList (static helpers only), these methods are
 * instance-level: they read the field definition for anchors and defaults,
 * and extract reads the first item. Construct it with a mocked
 * ComponentFieldConfigInterface whose hasComponentValues() returns FALSE —
 * that keeps the constructor's appendItem() path (which needs the typed-data
 * container) out of play — and stub the item via setStubItemValue().
 */
final class TestHybridComposerList extends NeoComponentTreeList {

  /**
   * The stubbed first item, if any.
   */
  private ?TestHybridItemStub $stubItem = NULL;

  /**
   * Stubs the value returned by ::first().
   *
   * @param array $value
   *   The item value with 'tree'/'props' keys (arrays or JSON strings).
   */
  public function setStubItemValue(array $value): void {
    $this->stubItem = new TestHybridItemStub($value);
  }

  /**
   * {@inheritdoc}
   */
  public function first() {
    return $this->stubItem;
  }

  /**
   * Exposes ::composeHybridValue().
   *
   * @param array $storedTree
   *   The decoded stored tree, without the root key.
   * @param array $storedProps
   *   The decoded stored props.
   *
   * @return array
   *   The merged item value with 'tree' and 'props' JSON strings.
   */
  public function composeHybridValuePublic(array $storedTree, array $storedProps): array {
    return $this->composeHybridValue($storedTree, $storedProps);
  }

  /**
   * Exposes ::extractHybridStorageValue().
   *
   * @return array
   *   An item value with 'tree' and 'props' arrays.
   */
  public function extractHybridStorageValuePublic(): array {
    return $this->extractHybridStorageValue();
  }

  /**
   * Returns the current orphan stash.
   *
   * @return array
   *   The orphans, keyed 'tree' and 'props'.
   */
  public function getHybridOrphans(): array {
    return $this->hybridOrphans;
  }

  /**
   * Seeds the orphan stash directly.
   *
   * @param array $orphans
   *   The orphans, keyed 'tree' and 'props'.
   */
  public function setHybridOrphans(array $orphans): void {
    $this->hybridOrphans = $orphans + ['tree' => [], 'props' => []];
  }

  /**
   * Exposes ::getTreeTupleUuids().
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   The tuple UUIDs only — section-only keys excluded.
   */
  public static function getTreeTupleUuidsPublic(array $tree): array {
    return static::getTreeTupleUuids($tree);
  }

}
