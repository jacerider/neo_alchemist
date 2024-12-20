<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

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

}
