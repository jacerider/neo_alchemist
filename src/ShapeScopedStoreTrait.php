<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * One store, cheap views onto it, and a per-shape deadline for writing.
 *
 * Two stores in this module hold what a shape's descendants were configured
 * with: NestedOptionMap holds their empty/default/access options, and
 * ChildShapeState holds what a producer decided about them. Both live on the
 * ROOT shape and are written by every shape beneath it, because the ids
 * involved are chained from the root and only the root can key them all.
 *
 * That gives both the same two problems, and this is the answer to both.
 *
 * **Delegation.** A non-root shape's view points at the root's store, so "if I
 * am the root, store; otherwise delegate to the root" is a property of the
 * object graph rather than a branch written out once per accessor. It used to
 * be seven such branches across the shape family.
 *
 * **A deadline.** A shape's descendants read what was recorded for them as
 * they are built, which happens from that shape's init() onwards, so a write
 * afterwards changes nothing and is a mistake. ::seal() marks that moment and
 * the writers check it. It is per shape, not per store: children initialize
 * strictly after their root, and go on being configured after the root has
 * finished.
 *
 * The deadline cannot live on the shape's own type, which is where the rest of
 * this lifecycle went. ComponentShapeSetupInterface can withdraw a *shape*
 * method from an initialised shape; these writers are not shape methods but a
 * collaborator's, reached through an accessor that is still read after init.
 *
 * A using class decides what its scope means. NestedOptionMap keys child
 * entries by it; ChildShapeState takes absolute ids and uses it only for the
 * deadline. Both make views with ::forShape() and read the arrays through
 * ::store().
 *
 * @see \Drupal\neo_alchemist\NestedOptionMap
 * @see \Drupal\neo_alchemist\ChildShapeState
 * @see \Drupal\neo_alchemist\ComponentShapeSetupInterface
 */
trait ShapeScopedStoreTrait {

  /**
   * The ids of shapes that have initialized, as a set.
   *
   * @var array<string, true>
   */
  private array $sealed = [];

  /**
   * Constructs a store, or a view onto one.
   *
   * Callers outside the using class construct the store — `new Whatever()` —
   * and reach shapes through ::forShape().
   *
   * @param string $shapeId
   *   The id of the shape this view speaks for. Empty on the store itself,
   *   which holds the arrays but speaks for no particular shape.
   * @param self|null $store
   *   The instance holding the arrays, or NULL when this instance is it.
   */
  public function __construct(
    private readonly string $shapeId = '',
    private readonly ?self $store = NULL,
  ) {}

  /**
   * Returns a view onto this store, scoped to one shape.
   *
   * Views are made per call rather than cached, because a shape's id changes
   * as parents are added to it and a stale scope would address the wrong
   * shape.
   *
   * @param string $shapeId
   *   The shape id to scope to.
   *
   * @return static
   *   The view.
   */
  public function forShape(string $shapeId): static {
    return new static($shapeId, $this->store());
  }

  /**
   * Records that this shape has initialized and takes no more writes.
   *
   * Honoured by the writers that record something for a shape's descendants,
   * and by nothing else — reads are what happens next, not a mistake, and a
   * writer addressing the whole store (a submitted form's own options, a
   * stored subtree re-entering) has no such deadline. Each using class says
   * which of its methods are which.
   *
   * @return $this
   */
  public function seal(): static {
    $this->store()->sealed[$this->shapeId] = TRUE;
    return $this;
  }

  /**
   * Fails when this shape has already initialized.
   *
   * @param string $subject
   *   What is being written, naming it for the failure message.
   *
   * @see ::seal()
   */
  private function assertNotSealed(string $subject): void {
    assert(
      !isset($this->store()->sealed[$this->shapeId]),
      "{$subject} for {$this->shapeId} must be set before it is initialized.",
    );
  }

  /**
   * Returns the instance holding the arrays.
   *
   * @return static
   *   The store, which is this instance when it is not a view.
   */
  private function store(): static {
    return $this->store ?? $this;
  }

}
