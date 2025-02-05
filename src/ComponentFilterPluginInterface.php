<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_component_filter plugins.
 */
interface ComponentFilterPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

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
   * @param string|null $value
   *   The value to summarize.
   *
   * @return string|null
   *   The summarized value of the filter plugin.
   */
  public function valueSummary(?string $value): ?string;

  /**
   * Form constructor.
   *
   * Filter plugin forms are embedded in component instance forms.
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
   *   An associative array containing the structure of the filter plugin form
   *   as built by static::buildForm().
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form. Calling code should pass on a subform
   *   state created through
   *   \Drupal\Core\Form\SubformState::createForSubform().
   */
  public function validateForm(array &$form, FormStateInterface $form_state);

  /**
   * Massages the form value.
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
   * Gets the value of the component filter.
   *
   * @param string|null $value
   *   The value to set.
   *
   * @return mixed
   *   The value of the filter.
   */
  public function getValue(string $value = NULL): mixed;

}
