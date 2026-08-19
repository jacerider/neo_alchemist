<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Applies a parent shape's constraints to one of its child shapes.
 *
 * Two kinds of constraint meet here, and they arrive from different places.
 *
 * The first is per-child and comes from a producer: a children-match provider
 * walks the mapping a site builder configured and flags individual children as
 * hidden, defaulted or locked, keyed by child shape id (see
 * ComponentValueChildrenMatchTrait). The second is inherited: a parent that is
 * itself empty, defaulted, inaccessible or unexpandable narrows what its
 * children may be.
 *
 * This used to live inside one of the two shape bases that build children, and
 * only that one. The other built its children through a different routine and
 * consulted none of it, so identical producer configuration behaved differently
 * depending on which base a shape happened to extend — a child mapped through
 * the default pseudo-field was pinned to its default under an object prop and
 * silently ignored under a link prop, where the flag landed in an array nobody
 * read. Nothing warned; the prop simply rendered the component author's example
 * text where a site builder had asked for nothing.
 *
 * Holding it here is what makes "both bases agree" a property of the code
 * rather than of two people remembering. A third children-bearing base cannot
 * forget the rules, because there is only one place that applies them.
 *
 * Stateless: everything it needs arrives as arguments, so it can be asserted
 * directly without a container.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::initChildShape()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\StructuredObjectShapeBase::getChildShapes()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueChildrenMatchTrait
 */
final class ChildOptionPolicy {

  /**
   * Applies the parent's constraints to a child shape.
   *
   * Must run before the child is initialized: the options it locks are read by
   * the child's own init(), and a lock applied afterwards changes nothing.
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $parent
   *   The parent shape building the child.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $child
   *   The child shape being built. Not yet initialized.
   * @param int|null $delta
   *   The delta the child is being built for, or NULL when the parent is not
   *   iterating.
   * @param int $count
   *   How many children the parent is building in this pass.
   */
  public function apply(ComponentShapeChildrenMatchPluginInterface $parent, ComponentShapePluginInterface $child, ?int $delta, int $count): void {
    $this->applyProducerFlags($parent, $child);
    $this->applyOptionToggles($parent, $child, $delta, $count);
    $this->applyInheritedState($parent, $child);
    // Last, and the order is load-bearing. ComponentShapeOption::setAccess() is
    // last-write-wins, unlike ::setLockedValue(), which is first-write-wins. A
    // parent that cannot expand has no per-child UI at all, so its withdrawal
    // has to outrank the access config scope grants a moment earlier — and a
    // media prop is exactly that pair, being an unexpandable object shape.
    $this->applyExpandability($parent, $child);
  }

  /**
   * Locks the child options a producer flagged for this specific child.
   *
   * A flag is three-valued: TRUE, FALSE, or absent. Absent means the producer
   * said nothing about this child and the child's own configuration stands, so
   * only a non-NULL answer locks anything.
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $parent
   *   The parent shape.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $child
   *   The child shape.
   */
  private function applyProducerFlags(ComponentShapeChildrenMatchPluginInterface $parent, ComponentShapePluginInterface $child): void {
    $childId = $child->id(TRUE);
    $state = $parent->getChildShapeState();

    $hidden = $state->getFlag($childId, ChildShapeState::HIDDEN);
    if (!is_null($hidden)) {
      $child->getOptionEmpty()->setLockedValue($hidden, 'Shape is hidden by parent shape.');
    }

    $default = $state->getFlag($childId, ChildShapeState::USE_DEFAULT);
    if (!is_null($default)) {
      $child->getOptionDefault()->setLockedValue($default, 'Shape is set as default by parent shape.');
    }

    $locked = $state->getFlag($childId, ChildShapeState::LOCKED);
    if (!is_null($locked)) {
      $child->getOptionAccess()->setLockedValue($locked, 'Shape is set as locked by parent shape.');
    }
  }

  /**
   * Withdraws option toggles that would be meaningless on this child.
   *
   * These govern what a site builder is offered in the form, not what the child
   * resolves to.
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $parent
   *   The parent shape.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $child
   *   The child shape.
   * @param int|null $delta
   *   The delta the child is being built for, if any.
   * @param int $count
   *   How many children the parent is building in this pass.
   */
  private function applyOptionToggles(ComponentShapeChildrenMatchPluginInterface $parent, ComponentShapePluginInterface $child, ?int $delta, int $count): void {
    if ($delta !== NULL && $count === 1) {
      $child->getOptionDefault()->setAccess(FALSE, 'Shape has a single prop, so setting as default is not allowed.');
      $child->getOptionEmpty()->setAccess(FALSE, 'Shape has a single prop, so setting as default is not allowed.');
    }
    elseif ($parent->isSingleProp()) {
      $child->getOptionEmpty()->setAccess(FALSE, 'Shape has a single prop, so setting as empty is not allowed.');
    }

    // A parent showing its own toggle unconditionally means its children have
    // to show theirs too, or the parent's form offers a choice the children
    // cannot honour.
    if ($parent->getOptionDefault()->isFormForced()) {
      $child->getOptionDefault()->alwaysShowForm(TRUE, 'Parent shape has default form forced.');
    }
    if ($parent->getOptionEmpty()->isFormForced()) {
      $child->getOptionEmpty()->alwaysShowForm(TRUE, 'Parent shape has empty form forced.');
    }
  }

  /**
   * Withdraws every toggle from a child whose parent cannot expand.
   *
   * There is no per-child configuration UI to show them in. Runs last, after
   * ::applyInheritedState(), because access is last-write-wins and this has to
   * outrank the grant config scope makes there.
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $parent
   *   The parent shape.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $child
   *   The child shape.
   */
  private function applyExpandability(ComponentShapeChildrenMatchPluginInterface $parent, ComponentShapePluginInterface $child): void {
    if ($parent instanceof ComponentShapeExpandedPluginInterface && !$parent->allowExpanded()) {
      $child->getOptionDefault()->setAccess(FALSE, 'Parent shape is not expandable.');
      $child->getOptionEmpty()->setAccess(FALSE, 'Parent shape is not expandable.');
      $child->getOptionAccess()->setAccess(FALSE, 'Parent shape is not expandable.');
    }
  }

  /**
   * Pushes the parent's own resolved state down onto the child.
   *
   * Only outside config scope. In config scope a site builder is authoring the
   * component itself, and the parent's current state is a preview of one
   * possible rendering rather than a decision about what its children may be —
   * so the children stay configurable and merely gain access.
   *
   * @param \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface $parent
   *   The parent shape.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $child
   *   The child shape.
   */
  private function applyInheritedState(ComponentShapeChildrenMatchPluginInterface $parent, ComponentShapePluginInterface $child): void {
    if ($parent->getScope() === 'config') {
      $child->getOptionAccess()->setAccess(TRUE, 'Scope is config.');
      return;
    }

    if ($parent->getOptionDefault()->isEnabled()) {
      $child->getOptionDefault()->setLockedValue(TRUE, 'Parent shape is set as default, so set child shape as default.');
    }
    if ($parent->getOptionEmpty()->isEnabled()) {
      $child->getOptionEmpty()->setLockedValue(TRUE, 'Parent shape is set as empty, so set child shape as empty.');
    }
    if ($parent->getOptionAccess()->isDisabled()) {
      $child->getOptionAccess()->setLockedValue(FALSE, 'Parent shape is disabled.');
    }
  }

}
