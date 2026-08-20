<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

/**
 * Answers whether the prop is active, required, editable or locked.
 *
 * The four questions a form or an access check asks before deciding whether to
 * show a prop and whether to let anyone change it. Each is a negotiation
 * between what the component declares and what the value providers allow: a
 * single provider refusing is enough to make a prop uneditable or locked.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapeOptionsInterface
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeStateInterface extends ComponentShapeIdentityInterface {

  /**
   * Sets the active status of the component shape.
   *
   * @param bool $active
   *   (optional) Whether the component shape is active. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function setActive(bool $active = TRUE): ComponentShapePluginInterface;

  /**
   * Is the prop active.
   *
   * @return bool
   *   Returns TRUE if the prop is active, FALSE otherwise.
   */
  public function isActive(): bool;

  /**
   * Sets the required status of the component shape.
   *
   * @param bool $required
   *   (optional) Whether the component shape is required. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function setRequired(bool $required = TRUE): ComponentShapePluginInterface;

  /**
   * Is the prop required.
   *
   * @return bool
   *   Returns TRUE if the prop is required, FALSE otherwise.
   */
  public function isRequired(): bool;

  /**
   * Determines if the component shape can be marked as required.
   *
   * @return bool
   *   TRUE if the component shape allows being required, FALSE otherwise.
   */
  public function allowRequired(): bool;

  /**
   * Enforces that the component shape is required.
   *
   * This method sets the `enforceRequired` and `required` properties to TRUE,
   * ensuring that the component shape is marked as required.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function enforceRequired(): ComponentShapePluginInterface;

  /**
   * Determines if the component shape can be dropped from a value when empty.
   *
   * Stays on the interface because ArrayShape asks it of each *child* shape it
   * is massaging, holding them as ComponentShapePluginInterface, so this is the
   * type the call resolves against.
   *
   * @return bool
   *   TRUE if a value carrying nothing for this shape may omit it, which is the
   *   case whenever the shape is not required.
   */
  public function allowUnsetEmpty(): bool;

  /**
   * Sets the editable state of the component.
   *
   * @param bool $editable
   *   (optional) The editable state to set. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setEditable(bool $editable = TRUE): ComponentShapePluginInterface;

  /**
   * Gets the editable state of the component.
   *
   * @return bool
   *   The editable state.
   */
  public function getEditable(): bool;

  /**
   * Determines if the component shape is editable.
   *
   * This method checks the `editable` property of the current instance and
   * iterates through all allowed value providers to determine if any of them
   * are not editable. If any provider is not editable, the component shape
   * is considered not editable. The iteration stops if a provider indicates
   * that processing should not continue.
   *
   * @return bool
   *   TRUE if the component shape is editable, FALSE otherwise.
   */
  public function isEditable(): bool;

  /**
   * Determines if the component shape is locked.
   *
   * This method checks the `locked` property of the current instance and
   * iterates through all allowed value providers to determine if any of them
   * are not locked. If any provider is not locked, the component shape
   * is considered not locked. The iteration stops if a provider indicates
   * that processing should not continue.
   *
   * @return bool
   *   TRUE if the component shape is locked, FALSE otherwise.
   */
  public function isLocked(): bool;

}
