<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Builds and processes the prop's editor form.
 *
 * The widget belongs here rather than with the field item because it is the
 * form that chooses and configures one; nothing outside form building asks a
 * shape for its widget.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeFieldItemInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeFormInterface extends ComponentShapeIdentityInterface {

  /**
   * Get the prop form.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return array|null
   *   The prop form.
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array;

  /**
   * Validate the prop form.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   */
  public function validateForm(array $form, FormStateInterface $form_state): void;

  /**
   * Massage the form values.
   *
   * @param array $values
   *   The form values.
   * @param array $original_values
   *   The original values.
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return array|null
   *   The massaged form values.
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array;

  /**
   * Massage the final values.
   *
   * @param array $values
   *   The shape values.
   *
   * @return array|null
   *   The massaged final values.
   */
  public function massageFinalValues(array $values): ?array;

  /**
   * Get the widget.
   *
   * @return \Drupal\Core\Field\WidgetInterface|null
   *   The widget.
   */
  public function getWidget(): ?WidgetInterface;

  /**
   * Set the widget type.
   *
   * @param string $widgetType
   *   The widget type.
   * @param array $widgetSettings
   *   The widget settings.
   *
   * @return $this
   */
  public function setWidget(string $widgetType, array $widgetSettings = []): ComponentShapePluginInterface;

  /**
   * Sets the widget settings.
   *
   * @param array $settings
   *   An associative array of widget settings.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setWidgetSettings(array $settings): ComponentShapePluginInterface;

}
