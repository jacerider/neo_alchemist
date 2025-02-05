<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentFilterInterface {

  /**
   * Gets the label (title) of the component filter.
   *
   * @return string
   *   The label of the filter.
   */
  public function label(): string;

  /**
   * Gets the UUID of the component filter.
   *
   * @return string|null
   *   The UUID or null if not set.
   */
  public function uuid(): ?string;

  /**
   * Returns the summarized configuration of the filter plugin.
   *
   * @return array
   *   An array of summarized configuration of the filter plugin.
   */
  public function settingsSummary(): array;

  /**
   * Returns the summarized value of the filter plugin.
   *
   * @return string|null
   *   The summarized value of the filter plugin.
   */
  public function valueSummary(): ?string;

  /**
   * Gets the component of the component filter.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component.
   */
  public function getComponent(): ComponentInterface;

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
   * Gets the plugin ID of the component filter.
   *
   * @return string
   *   The plugin ID.
   */
  public function getPluginId(): string;

  /**
   * Sets the plugin ID of the component filter.
   *
   * @param string $pluginId
   *   The plugin ID to set.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setPluginId(string $pluginId): self;

  /**
   * Retrieves the plugin settings.
   *
   * @return array
   *   An array of plugin settings.
   */
  public function getPluginSettings(): array;

  /**
   * Sets the plugin settings.
   *
   * @param array $pluginSettings
   *   An associative array of plugin settings.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setPluginSettings(array $pluginSettings): self;

  /**
   * Retrieves the plugin instance.
   *
   * This method checks if the plugin instance is already set. If not, it
   * attempts to create an instance of the plugin using the plugin ID and
   * component settings. If the plugin ID is valid and the manager has a
   * definition for it, the plugin instance is created and stored.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentFilterPluginInterface|null
   *   The plugin instance if available, or NULL if it could not be created.
   */
  public function getPlugin(): ?ComponentFilterPluginInterface;

  /**
   * Determines if the component filter is new.
   *
   * @return bool
   *   TRUE if the component filter is new, FALSE otherwise.
   */
  public function isNew(): bool;

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

  /**
   * Converts the component filter to an array.
   *
   * @return array
   *   An associative array representing the filter.
   */
  public function toArray(): array;

}
