<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface;

/**
 * A configured filter stored on a component.
 *
 * Everything about being "a plugin id plus settings under a uuid" is inherited;
 * what is this family's own is the title, description, editability and the
 * default/override value pair below.
 */
interface ComponentFilterInterface extends ConfiguredPluginWrapperInterface {

  /**
   * Returns the summarized value of the filter plugin.
   *
   * @return string|null
   *   The summarized value of the filter plugin.
   */
  public function valueSummary(): ?string;

  /**
   * Sets the title of the component filter.
   *
   * @param string $title
   *   The title to set.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setTitle(string $title): self;

  /**
   * Gets the description of the component filter.
   *
   * @return string
   *   The description or null if not set.
   */
  public function getDescription(): string;

  /**
   * Sets the description of the component filter.
   *
   * @param string $description
   *   The description to set.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setDescription(string $description): self;

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\ComponentFilterPluginInterface|null
   *   The filter plugin instance, or NULL when none is configured.
   */
  public function getPlugin(): ?ComponentFilterPluginInterface;

  /**
   * Checks if the component filter is editable.
   *
   * @return bool
   *   TRUE if editable, FALSE otherwise.
   */
  public function isEditable(): bool;

  /**
   * Sets whether the component filter is editable.
   *
   * @param bool $editable
   *   TRUE to make editable, FALSE otherwise.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setEditable(bool $editable): self;

  /**
   * Checks if the component filter is required.
   *
   * @return bool
   *   TRUE if required, FALSE otherwise.
   */
  public function isRequired(): bool;

  /**
   * Sets whether the component filter is required.
   *
   * @param bool $required
   *   TRUE to make required, FALSE otherwise.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setRequired(bool $required): self;

  /**
   * Form constructor.
   *
   * Filter forms are embedded in component instance forms.
   *
   * @param array $form
   *   An associative array containing the initial structure of the filter form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
   * @param bool $is_default_form
   *   TRUE if the form is the default form, FALSE otherwise.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array;

  /**
   * Form validation handler.
   *
   * @param array $form
   *   An associative array containing the structure of the filter form as built
   *   by static::buildForm().
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
   */
  public function validateForm(array &$form, FormStateInterface $form_state);

  /**
   * Massages the form value using the plugin if available.
   *
   * @param array $value
   *   The form value to be massaged.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return string|null
   *   The massaged form value, or NULL if no plugin is available.
   */
  public function massageFormValue(array $value, array $form, FormStateInterface $form_state): ?string;

  /**
   * Determines if the component filter is empty.
   *
   * @return bool
   *   TRUE if the component filter is empty, FALSE otherwise.
   */
  public function isEmpty(): bool;

  /**
   * Determines if the component filter allows use of the default toggle.
   *
   * @return bool
   *   TRUE if the component filter allows a default value, FALSE otherwise.
   */
  public function allowDefault(): bool;

  /**
   * Gets the default value.
   *
   * @return string|null
   *   The default value, or NULL if not set.
   */
  public function getDefaultValue(): ?string;

  /**
   * Sets the default value of the component filter.
   *
   * @param string|null $value
   *   The value to set.
   *
   * @return self
   *   The current instance of the component filter.
   */
  public function setDefaultValue(?string $value): self;

  /**
   * Determines if the component filter has a default value.
   *
   * @return bool
   *   TRUE if the component filter has a default value, FALSE otherwise.
   */
  public function hasDefaultValue(): bool;

  /**
   * Gets the override value.
   *
   * @return string|null
   *   The override value, or NULL if not set.
   */
  public function getOverrideValue(): ?string;

  /**
   * Sets the override value of the component filter.
   *
   * @param string|null $value
   *   The value to set.
   *
   * @return self
   *   The current instance of the component filter.
   */
  public function setOverrideValue(?string $value): self;

  /**
   * Determines if the component filter has an override value.
   *
   * @return bool
   *   TRUE if the component filter has an override value, FALSE otherwise.
   */
  public function hasOverrideValue(): bool;

  /**
   * Retrieves the value of the component filter.
   *
   * @return string|null
   *   The value of the component filter as a string.
   */
  public function getValue(): ?string;

  /**
   * Processes the value using the plugin if available.
   *
   * This method checks if a plugin is available and uses it to process the
   * current value. If no plugin is available, it returns NULL.
   *
   * @return string|null
   *   The processed value as a string if the plugin is available, or NULL if
   *   no plugin is available.
   */
  public function getProcessedValue(): ?string;

}
