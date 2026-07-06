<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'remote_video',
  label: new TranslatableMarkup('Remote Video'),
  default_plugins: ['media'],
)]
class VideoRemoteShape extends MediaShapeBase {

  /**
   * {@inheritDoc}
   */
  protected bool $optionDefaultInitValue = TRUE;

  /**
   * {@inheritDoc}
   */
  public function getSupportedMediaTypes(): array {
    return ['remote_video'];
  }

  /**
   * {@inheritDoc}
   */
  protected function isFieldItemEmpty(): bool {
    $value = $this->fieldItem->getValue();
    // Since this shape provides non-standard default values, we do not consider
    // the field as empty if it has an src value.
    if (!empty($value['src'])) {
      return FALSE;
    }
    return parent::isFieldItemEmpty();
  }

  /**
   * {@inheritDoc}
   */
  public function getValueFromMedia(MediaInterface $media): array {
    $source = $media->getSource();
    $thumbnailUri = $source->getMetadata($media, 'thumbnail_uri');
    return [
      'src' => $source->getSourceFieldValue($media),
      'thumbnail' => !empty($thumbnailUri) ? \Drupal::service('file_url_generator')->generateString($thumbnailUri) : NULL,
      'thumbnail_width' => $source->getMetadata($media, 'width'),
      'thumbnail_height' => $source->getMetadata($media, 'height'),
      'title' => $source->getMetadata($media, 'title'),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultPreview(): ?array {
    $value = $this->getValue();
    if (!empty($value['thumbnail'])) {
      return [
        '#type' => 'inline_template',
        '#template' => '<div class="media-library-item--preview"><img src="{{ thumbnail }}"{% if alt %} alt="{{ alt }}"{% endif %}{% if thumbnail_width %} width="{{ thumbnail_width }}"{% endif %}{% if thumbnail_height %} height="{{ thumbnail_height }}"{% endif %} /></div>',
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
      'src' => 'https://www.youtube.com/watch?v=ScMzIvxBSi4',
      'thumbnail' => 'https://img.youtube.com/vi/ScMzIvxBSi4/hqdefault.jpg',
      'title' => 'Placeholder Video',
    ];
  }

}
