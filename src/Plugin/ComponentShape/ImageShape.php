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
 */
#[ComponentShape(
  prop: 'image',
  label: new TranslatableMarkup('Image'),
  default_plugins: ['media'],
)]
class ImageShape extends MediaShapeBase {

  /**
   * {@inheritDoc}
   */
  protected bool $optionDefaultInitValue = TRUE;

  /**
   * {@inheritDoc}
   */
  public function getSupportedMediaTypes(): array {
    return ['image'];
  }

  /**
   * {@inheritDoc}
   *
   * The `size` key is not something an editor picked an image with — it is
   * seeded unconditionally by the `media_image_size` modifier, which is scoped
   * to `image` refs and so lands on this shape and no other. Left counting as
   * content, that lone key keeps an imageless value looking authored, and the
   * fallback that should have supplied a picture never gets its turn.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaImageSizeValue::provideDefaultValue()
   */
  protected function getPresentationalValueKeys(): array {
    return array_merge(parent::getPresentationalValueKeys(), ['size']);
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
    $file = $this->getFileFromMedia($media);
    if ($file instanceof FileInterface) {
      $source = $media->getSource();
      return [
        'src' => $file->createFileUrl(),
        'uri' => $file->getFileUri(),
        'alt' => $source->getMetadata($media, 'thumbnail_alt_value') ?? '',
        'width' => $source->getMetadata($media, 'width'),
        'height' => $source->getMetadata($media, 'height'),
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
        '#template' => '<div class="media-library-item--preview"><img src="{{ src }}"{% if alt %} alt="{{ alt }}"{% endif %}{% if width %} width="{{ width }}"{% endif %}{% if height %} height="{{ height }}"{% endif %} /></div>',
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
      'src' => 'https://placehold.co/200x100.png',
      'alt' => 'Example image',
      'width' => 200,
      'height' => 100,
    ];
  }

}
