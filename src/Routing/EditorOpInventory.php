<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Routing;

/**
 * The editor's op vocabulary, as one table.
 *
 * The eight ops the editor offers on a component instance — edit, sort, clone,
 * delete, add before, add after, move up, move down — are not the six routes
 * behind them, and nothing declared that until now. Add before and add after
 * are both the `library` route with a position; move up and move down are both
 * the `move` route with a direction. That decomposition lived nowhere but a
 * runtime split of the op identifier on a hyphen in the client, which made the
 * ids load-bearing strings: renaming one silently changed what it did.
 *
 * This table declares the vocabulary once. For each op it states the verb, the
 * position or direction, the route rel it targets, the label and the icon — so
 * the decomposition is a fact of the table rather than an inference, and a
 * consumer reads a field instead of re-parsing a string. The chrome and the
 * client still derive today; as they move onto this table (later phase-two
 * tickets) renaming an op becomes a one-place change. The labels and icons
 * live here, not on the per-component emission, because the editor chrome is
 * rendered once and reused across selections: what a button says is a fact
 * about the op, not about the component that happens to be selected.
 *
 * The `rel` of every op is a member op the route family registers
 * (@see \Drupal\neo_alchemist\Routing\EditorRouteFamily), so an op cannot name
 * a route no scope serves — a cross-check pinned at the kernel level
 * (@see \Drupal\Tests\neo_alchemist\Kernel\EditorOpInventoryUrlTest).
 *
 * @see \Drupal\neo_alchemist\Routing\EditorOp
 */
final class EditorOpInventory {

  /**
   * The eight ops, keyed by identifier, in the order the chrome renders them.
   *
   * The four verb ops come first (the bottom bar's "acting on the component
   * itself" group), then the four positional ops. An op is positional exactly
   * when it carries a position or a direction, so the chrome's two groups are
   * derivable from the table without a separate flag.
   *
   * `icon` is NULL for the verb ops: the chrome passes no explicit icon for
   * them, letting neo_icon_admin() derive one from the label, so an icon here
   * would change what renders. `short` is the abbreviated label the bottom bar
   * shows for the two add ops; the rest have none.
   */
  private const OPS = [
    'edit' => [
      'verb' => 'edit',
      'position' => NULL,
      'direction' => NULL,
      'rel' => 'edit',
      'label' => 'Edit',
      'short' => NULL,
      'icon' => NULL,
    ],
    'sort' => [
      'verb' => 'sort',
      'position' => NULL,
      'direction' => NULL,
      'rel' => 'sort',
      'label' => 'Sort',
      'short' => NULL,
      'icon' => NULL,
    ],
    'clone' => [
      'verb' => 'clone',
      'position' => NULL,
      'direction' => NULL,
      'rel' => 'clone',
      'label' => 'Clone',
      'short' => NULL,
      'icon' => NULL,
    ],
    'delete' => [
      'verb' => 'delete',
      'position' => NULL,
      'direction' => NULL,
      'rel' => 'delete',
      'label' => 'Delete',
      'short' => NULL,
      'icon' => NULL,
    ],
    'add-before' => [
      'verb' => 'add',
      'position' => 'before',
      'direction' => NULL,
      'rel' => 'library',
      'label' => 'Add Component Before',
      'short' => 'Before',
      'icon' => 'plus',
    ],
    'add-after' => [
      'verb' => 'add',
      'position' => 'after',
      'direction' => NULL,
      'rel' => 'library',
      'label' => 'Add Component After',
      'short' => 'After',
      'icon' => 'plus',
    ],
    'move-up' => [
      'verb' => 'move',
      'position' => NULL,
      'direction' => 'up',
      'rel' => 'move',
      'label' => 'Move Component Up',
      'short' => NULL,
      'icon' => 'arrow-up',
    ],
    'move-down' => [
      'verb' => 'move',
      'position' => NULL,
      'direction' => 'down',
      'rel' => 'move',
      'label' => 'Move Component Down',
      'short' => NULL,
      'icon' => 'arrow-down',
    ],
  ];

  /**
   * The eight ops as records, keyed by identifier, in render order.
   *
   * @return \Drupal\neo_alchemist\Routing\EditorOp[]
   *   The op records, keyed by op id.
   */
  public function ops(): array {
    $ops = [];
    foreach (array_keys(self::OPS) as $id) {
      $ops[$id] = $this->getOp($id);
    }
    return $ops;
  }

  /**
   * One op record.
   *
   * @param string $id
   *   The op identifier.
   *
   * @return \Drupal\neo_alchemist\Routing\EditorOp
   *   The op record.
   *
   * @throws \InvalidArgumentException
   *   When no op has that identifier.
   */
  public function getOp(string $id): EditorOp {
    $spec = self::OPS[$id] ?? throw new \InvalidArgumentException("Unknown editor op: $id");
    return new EditorOp(
      $id,
      $spec['verb'],
      $spec['position'],
      $spec['direction'],
      $spec['rel'],
      $spec['label'],
      $spec['short'],
      $spec['icon'],
    );
  }

}
