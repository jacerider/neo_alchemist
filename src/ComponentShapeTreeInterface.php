<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Walks the shape tree.
 *
 * A component's props are a tree: an object shape holds children, which hold
 * children. This role is how a caller moves through it — up to a parent or the
 * root, down to every descendant — and how a shape learns where it sits.
 *
 * The navigation methods hand back whole shapes rather than tree roles,
 * because arriving somewhere in the tree is normally the prelude to asking
 * that shape something else entirely.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeExpansionInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeTreeInterface extends ComponentShapeIdentityInterface {

  /**
   * Set the nested delta of the shape.
   *
   * Stays on the interface because the shape bases set it on a *child* shape
   * they hold as ComponentShapePluginInterface, so this is the type the call
   * resolves against. Reading the delta back is internal to the base.
   *
   * @param int $delta
   *   The nested delta.
   *
   * @return $this
   */
  public function setDelta(int $delta): ComponentShapePluginInterface;

  /**
   * Adds a parent shape to the current component shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $parent
   *   The parent shape to be added.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function addParentShape(ComponentShapePluginInterface $parent): ComponentShapePluginInterface;

  /**
   * Retrieves the immediate parent shape of the current component shape.
   *
   * @return ComponentShapePluginInterface|null
   *   The immediate parent shape if it exists, or NULL if there is no parent.
   */
  public function getParentShape(): ?ComponentShapePluginInterface;

  /**
   * Retrieves the parent shapes of the current component shape.
   *
   * The order will be from the first parent to the last parent.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of parent shapes.
   */
  public function getParentShapes(): array;

  /**
   * Retrieves the root parent shape of the current component shape.
   *
   * If the shape is the root shape, it will return itself.
   *
   * @return ComponentShapePluginInterface
   *   The root parent shape if it exists, or NULL if there is no parent.
   */
  public function getRootShape(): ComponentShapePluginInterface;

  /**
   * Retrieves all child shapes recursively.
   *
   * This method collects all child shapes, including nested child shapes,
   * and returns them in a sorted array.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   * @param bool $includeDeltas
   *   Whether to include the delta values in the returned shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An associative array of all child shapes, keyed by their nested IDs.
   */
  public function getAllShapes($includeSelf = FALSE, $includeDeltas = FALSE): array;

  /**
   * Checks if the component shape is the root shape.
   *
   * @return bool
   *   TRUE if the component shape is the root shape, FALSE otherwise.
   */
  public function isRoot(): bool;

  /**
   * Checks if the component shape is nested.
   *
   * @return bool
   *   TRUE if the component shape is nested, FALSE otherwise.
   */
  public function isNested(): bool;

  /**
   * Retrieves the concatenated title of the current component and its parents.
   *
   * This method constructs a title string by combining the title of the current
   * component with the titles of its parent components. The titles are
   * separated by a colon and a space (": ").
   *
   * @param bool $includeRoot
   *   (optional) Whether to include the current component in the path. Defaults
   *   to TRUE.
   *
   * @return string
   *   The concatenated title string of the current component and its parents.
   */
  public function getNestedTitle($includeRoot = TRUE): string;

}
