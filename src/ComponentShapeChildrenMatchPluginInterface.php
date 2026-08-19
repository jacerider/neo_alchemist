<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Minimum contract consumed by ComponentValueChildrenMatchTrait.
 *
 * Shapes that satisfy this interface can be used as the root of a
 * field-to-child mapping configuration (see entity_load, site_settings, etc.
 * ComponentValue providers). The wider ComponentShapeChildrenPluginInterface
 * extends this and adds introspection/lock methods that the full
 * ChildrenShapeBase flow requires.
 */
interface ComponentShapeChildrenMatchPluginInterface extends ComponentShapePluginInterface {

  /**
   * Get child shapes.
   *
   * @param int|null $delta
   *   The delta of the child shape to retrieve.
   * @param mixed $value
   *   (optional) The value to set on the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The child shapes.
   */
  public function getChildShapes(int|null $delta = NULL, mixed $value = NULL): array;

  /**
   * Get the names of the child shapes.
   *
   * @return string[]
   *   The names of the child shapes.
   */
  public function getChildShapeNames(): array;

  /**
   * Get an uninitialized child shape for value resolution.
   *
   * Safe to call while getDefaultValue() is being memoized — unlike
   * getChildShapes(), which routes through getChildSchema() ->
   * getDefaultValue() and recurses when called from a value provider.
   * The returned shape is never init()'d; use it only for schema-level
   * introspection and value conversion (getValueFromMedia(), resolveValue()).
   *
   * @param string $name
   *   The child shape (property) name.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The uninitialized child shape, or NULL if no child of that name exists.
   */
  public function getValueResolverShape(string $name): ?ComponentShapePluginInterface;

  /**
   * Check if the schema is a single property.
   *
   * @return bool
   *   Whether the schema is a single property.
   */
  public function isSingleProp(): bool;

  /**
   * Gets what producers have decided about this shape's individual children.
   *
   * Always the ROOT shape's state, whichever shape in the tree is asked: the
   * ids a producer records are chained from the root, so only the root can key
   * them all.
   *
   * This one accessor replaced seven methods that were each a copy of the same
   * root-delegation branch.
   *
   * @return \Drupal\neo_alchemist\ChildShapeState
   *   The child shape state.
   */
  public function getChildShapeState(): ChildShapeState;

  /**
   * Gets the value plugin configuration to attach to one child.
   *
   * Not a plain read of ::getChildShapeState(): it also folds in the plugins
   * the root shape stores against that child id, which an iterable shape needs
   * because its children carry a delta and so are not found on the base shape.
   *
   * @param string $shapeId
   *   The id of the shape. This is not the shape name but the unique id.
   *
   * @return array
   *   The child shape plugins.
   */
  public function getChildShapePlugins(string $shapeId): array;

}
