<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Reads the empty, default and access options recorded for the prop.
 *
 * Every shape carries three options — render nothing, sit on its own default,
 * or let an editor change it. Where they come from is NestedOptionMap: one
 * store on the root shape keyed by shape id, because a parent sets options for
 * children that do not exist yet.
 *
 * @see \Drupal\neo_alchemist\NestedOptionMap
 * @see \Drupal\neo_alchemist\ComponentShapeOption
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeOptionsInterface extends ComponentShapeIdentityInterface {

  /**
   * Retrieves the 'empty' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'empty' option of the component shape plugin.
   */
  public function getOptionEmpty(): ComponentShapeOption;

  /**
   * Retrieves the 'default' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'default' option of the component shape plugin.
   */
  public function getOptionDefault(): ComponentShapeOption;

  /**
   * Retrieves the 'access' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'access' option of the component shape plugin.
   */
  public function getOptionAccess(): ComponentShapeOption;

  /**
   * Returns the options recorded for this shape and its descendants.
   *
   * The map is the store behind the empty/default/access options: what a site
   * builder configured, and what a value provider decided for children that do
   * not exist yet. It is scoped to this shape, so a child is named relative to
   * it and building the key is never a caller's business.
   *
   * This replaced fourteen methods — a get/set × fallback/saved × three option
   * names grid over the same two arrays — three of which carried a flag
   * controlling key prefixing that no caller ever passed and whose default two
   * of them disagreed about.
   *
   * @return \Drupal\neo_alchemist\NestedOptionMap
   *   The map, scoped to this shape.
   */
  public function getNestedOptionMap(): NestedOptionMap;

  /**
   * Replaces the options recorded for this shape.
   *
   * Replaces rather than merges — see NestedOptionMap::replaceOwn().
   *
   * @param array $options
   *   An associative array of options to set for the shape.
   * @param string $id
   *   The shape id to set them for, defaulting to this shape's own.
   *
   * @return $this
   *   Returns the current instance for method chaining.
   */
  public function setOptions(array $options, ?string $id = NULL): ComponentShapePluginInterface;

  /**
   * Retrieves the options recorded for this shape.
   *
   * @param string $id
   *   The shape id to read them for, defaulting to this shape's own.
   *
   * @return array
   *   An associative array of options for the shape.
   */
  public function getOptions(?string $id = NULL): array;

}
