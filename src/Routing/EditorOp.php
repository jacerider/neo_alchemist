<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Routing;

/**
 * One editor op, as a declared record rather than a parsed identifier.
 *
 * An op is an operation the editor offers on a component instance — edit, sort,
 * clone, delete, add before, add after, move up, move down. The client used to
 * infer what an op meant by splitting its identifier on a hyphen at runtime, so
 * "add-before" was two facts (a verb and a position) hidden inside one string.
 * This record states those facts instead: a consumer reading it needs no
 * knowledge of how the id is spelled, so as the chrome and client move onto the
 * table (later phase-two tickets) renaming an op becomes a one-place change.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorOpInventory
 */
final class EditorOp {

  public function __construct(
    /**
     * The op identifier — the data-op key the entity emits and the chrome uses.
     */
    public readonly string $id,
    /**
     * The action the op performs (edit, sort, clone, delete, add, move).
     */
    public readonly string $verb,
    /**
     * The sibling position an add op inserts at (before/after), else NULL.
     */
    public readonly ?string $position,
    /**
     * The direction a move op shifts (up/down), else NULL.
     */
    public readonly ?string $direction,
    /**
     * The route-family member op this op's URL targets.
     *
     * Add ops target the `library` route (with a position); move ops target the
     * `move` route (with a direction); the verb ops name their own route.
     *
     * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
     */
    public readonly string $rel,
    /**
     * The op's full label, as the chrome renders it today.
     */
    public readonly string $label,
    /**
     * A short label the space-constrained bottom bar renders, else NULL.
     */
    public readonly ?string $short,
    /**
     * The op's icon name, or NULL to let neo_icon_admin() derive one.
     */
    public readonly ?string $icon,
  ) {}

}
