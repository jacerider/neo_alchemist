<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;

/**
 * One child shape being filled for one iterated entity.
 *
 * Everything a field handler needs to produce a value, in place of the six
 * positional arguments the trait's handlers took.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface::fetch()
 */
final class ChildrenMatchField {

  /**
   * Constructs a ChildrenMatchField.
   *
   * @param string $shapeId
   *   The chained shape id of the child being filled — `rootId~child` and, at
   *   nested _expand/_reference levels, `rootId~child~grandchild`. This is the
   *   key every ChildShapeState call is made against.
   * @param string $shapeName
   *   The child's own name, unchained.
   * @param int $delta
   *   The position of the iterated entity among those that SURVIVED filtering,
   *   which is not necessarily its position in the source's own result set.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface $shape
   *   The ROOT children-match shape. It stays the root through every recursion
   *   because the child-state calls are keyed by a chained id the root owns.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being read.
   * @param array $settings
   *   The stored settings for this child — `field`, and whatever the chosen
   *   field kind adds beside it.
   */
  public function __construct(
    public readonly string $shapeId,
    public readonly string $shapeName,
    public readonly int $delta,
    public readonly ComponentShapeChildrenMatchPluginInterface $shape,
    public readonly ContentEntityInterface $entity,
    public readonly array $settings,
  ) {}

}
