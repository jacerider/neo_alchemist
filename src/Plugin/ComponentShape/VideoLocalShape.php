<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 *
 * Binds a prop to Drupal's local "video" media type (an uploaded file, e.g.
 * mp4). The remote (oEmbed) counterpart is VideoRemoteShape.
 */
#[ComponentShape(
  prop: 'video',
  label: new TranslatableMarkup('Video'),
  default_plugins: ['media'],
)]
class VideoLocalShape extends MediaShapeBase {

  /**
   * {@inheritDoc}
   */
  protected bool $optionDefaultInitValue = TRUE;

  /**
   * {@inheritDoc}
   */
  public function getSupportedMediaTypes(): array {
    return ['video'];
  }

  /**
   * {@inheritDoc}
   */
  public function getValueFromMedia(MediaInterface $media): array {
    $file = $this->getFileFromMedia($media);
    if ($file instanceof FileInterface) {
      $source = $media->getSource();
      // The local video source only exposes a real thumbnail when one has been
      // generated; otherwise it falls back to a generic media icon, which is a
      // poor poster for a background video. Only surface a meaningful poster.
      $poster = NULL;
      $thumbnailUri = $source->getMetadata($media, 'thumbnail_uri');
      if (!empty($thumbnailUri) && !str_contains($thumbnailUri, 'media-icons/generic')) {
        $poster = \Drupal::service('file_url_generator')->generateString($thumbnailUri);
      }
      return [
        'src' => $file->createFileUrl(),
        'uri' => $file->getFileUri(),
        'mime' => $file->getMimeType(),
        'poster' => $poster,
        'title' => $media->label(),
        'target_id' => $media->id(),
      ];
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultPreview(): ?array {
    $value = $this->getValue();
    if (!empty($value['src'])) {
      return [
        '#type' => 'inline_template',
        '#template' => '<div class="media-library-item--preview"><video src="{{ src }}" muted playsinline{% if poster %} poster="{{ poster }}"{% endif %} style="max-width:100%;height:auto"></video></div>',
        '#context' => $value,
      ];
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    $prop['examples'] = [
      'src' => 'theme://videos/placeholder.mp4',
    ];
  }

}
