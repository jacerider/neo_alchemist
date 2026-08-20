<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeMediaPluginInterface {

  /**
   * Returns the supported media types.
   *
   * @return array
   *   An array of supported media types.
   */
  public function getSupportedMediaTypes(): array;

  /**
   * Retrieves the media value for the media shape component.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media item to retrieve the value from.
   *
   * @return array
   *   The value to be used for the component.
   */
  public function getValueFromMedia(MediaInterface $media): array;

  /**
   * Gets the default preview.
   *
   * Should be a renderable array.
   *
   * @return array|null
   *   The image preview data or null if not applicable.
   */
  public function getDefaultPreview(): ?array;

  /**
   * Builds the media override form.
   *
   * @param array $form
   *   The widget form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\media\MediaInterface|null $media
   *   The media entity, if available.
   */
  public function buildMediaOverrideForm(array $form, FormStateInterface $form_state, ?MediaInterface $media = NULL): array;

  /**
   * Massages the media override values using the plugin if available.
   *
   * @param array $values
   *   The form values to be massaged.
   * @param array $override_values
   *   The override values from the override form.
   * @param array $original_values
   *   The original values.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   */
  public function massageMediaOverrideValues(array &$values, array $override_values, array $original_values, array $form, FormStateInterface $form_state): void;

}
