<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

/**
 * Answers what is expanded.
 *
 * An expanded child is one a site builder has pulled out to configure in its
 * own right rather than as part of its parent. The set is recorded once on the
 * root shape, so every shape in a tree gives the same answer.
 *
 * Distinct from ComponentShapeExpandedPluginInterface, which is a capability a
 * shape plugin implements to say expansion is possible for its kind at all.
 * This role reports what a particular component has actually expanded.
 *
 * `getPluginShapes()` sits here rather than with the value providers despite
 * its name: it is `getAllShapes()` filtered by `allowConfigurablePlugins()`,
 * and that predicate means "my parent is expanded". So it answers the same
 * question as `getExpandedableShapes()` — which descendants get a
 * configuration UI of their own — and the one caller reaches for both together
 * while building exactly that.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapeExpandedPluginInterface
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeExpansionInterface extends ComponentShapeIdentityInterface {

  /**
   * Checks if the component shape supports expansion.
   *
   * This is different than isExpandable as this just checks to see if expansion
   * is possible. The isExpandable method checks if the component has allowed
   * it.
   *
   * Stays on the interface because a shape asks it of its *parents*, which
   * getParentShapes() hands back as ComponentShapePluginInterface, so this is
   * the type the call resolves against.
   *
   * @return bool
   *   TRUE if the component shape supports expansion, FALSE otherwise.
   */
  public function supportsExpansion(): bool;

  /**
   * Checks if the component shape is expandable.
   *
   * This method checks to see both that the shape supports expansion AND that
   * the shape has allowed it.
   *
   * @return bool
   *   TRUE if the component shape is expandable, FALSE otherwise.
   */
  public function isExpandable(): bool;

  /**
   * Set the array of child shape nested ids that are expanded.
   *
   * @param array $expanded
   *   An array of child shape nested ids that are expanded.
   *
   * @return $this
   */
  public function setExpanded(array $expanded): ComponentShapePluginInterface;

  /**
   * Get the array of child shape nested ids that are expanded.
   *
   * Expanded settings are stored on the root parent shape unless this shape
   * is not expanded, which means it is the root and has the expanded settings.
   *
   * @return array
   *   An array of child shape nested ids that are expanded.
   */
  public function getExpanded(): array;

  /**
   * Retrieves all expandable shapes recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   *
   * @return array
   *   An array of expandable shapes.
   */
  public function getExpandedableShapes($includeSelf = FALSE): array;

  /**
   * Retrieves all shapes that allow plugins recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   *
   * @return array
   *   An array of expanded children shapes.
   */
  public function getPluginShapes($includeSelf = FALSE): array;

}
