<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginInterface;

/**
 * Interface for neo_component_filter plugins.
 *
 * Inheriting ::isApplicable() is what closed the reported defect: the filter
 * form used to list every definition, because the family had no way to say a
 * plugin does not apply and its manager had no method to ask.
 */
interface ComponentFilterPluginInterface extends ConfiguredPluginInterface {

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
  public function getValue(?string $value = NULL): mixed;

}
