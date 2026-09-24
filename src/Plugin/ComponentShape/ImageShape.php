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
  public function getValueFromMedia(MediaInterface $media): array {
    $file = $this->getFileFromMedia($media);
    if ($file instanceof FileInterface) {
      $source = $media->getSource();
      $width = $source->getMetadata($media, 'width');
      $height = $source->getMetadata($media, 'height');
      if (!$width || !$height) {
        [$width, $height] = $this->measureSvg($file) + [$width, $height];
      }
      return [
        'src' => $file->createFileUrl(),
        'uri' => $file->getFileUri(),
        'alt' => $source->getMetadata($media, 'thumbnail_alt_value') ?? '',
        'width' => $width,
        'height' => $height,
        'target_id' => $media->id(),
      ];
    }
    return [];
  }

  /**
   * Reads an SVG's intrinsic dimensions from its root element.
   *
   * No image toolkit core ships can read an SVG, so the image field stores no
   * width or height for one. Without them a template's `<img>` has no aspect
   * ratio to reserve space with, and the page shifts when the SVG loads.
   *
   * Absolute `width` and `height` attributes (unitless or `px`) win. A
   * relative one (`100%`, `em`) says nothing about the image's own size, so
   * the `viewBox` supplies what is missing, keeping its aspect ratio.
   *
   * @param \Drupal\file\FileInterface $file
   *   The image file.
   *
   * @return int[]
   *   The width and height, as a list, or an empty array when the file is not
   *   an SVG or its size cannot be read.
   */
  protected function measureSvg(FileInterface $file): array {
    $uri = $file->getFileUri();
    if ($file->getMimeType() !== 'image/svg+xml'
      && !preg_match('/\.svg$/i', $uri)) {
      return [];
    }
    $contents = @file_get_contents($uri);
    if (!$contents) {
      return [];
    }
    $errors = libxml_use_internal_errors(TRUE);
    $svg = simplexml_load_string($contents, NULL, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($errors);
    if (!$svg instanceof \SimpleXMLElement) {
      return [];
    }
    $attributes = $svg->attributes();
    $width = $this->svgLength((string) ($attributes['width'] ?? ''));
    $height = $this->svgLength((string) ($attributes['height'] ?? ''));
    if ($width && $height) {
      return [$width, $height];
    }
    $viewBox = trim((string) ($attributes['viewBox'] ?? ''));
    $box = preg_split('/[\s,]+/', $viewBox);
    if (count($box) !== 4 || !is_numeric($box[2]) || !is_numeric($box[3])
      || $box[2] <= 0 || $box[3] <= 0) {
      return [];
    }
    $ratio = $box[3] / $box[2];
    if ($width) {
      return [$width, (int) round($width * $ratio)];
    }
    if ($height) {
      return [(int) round($height / $ratio), $height];
    }
    return [(int) round((float) $box[2]), (int) round((float) $box[3])];
  }

  /**
   * Parses an absolute SVG length, unitless or in `px`.
   *
   * @param string $length
   *   The attribute value.
   *
   * @return int|null
   *   The rounded length, or NULL when it is relative, zero or not a length.
   */
  protected function svgLength(string $length): ?int {
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:px)?\s*$/', $length, $match)) {
      return NULL;
    }
    $value = (int) round((float) $match[1]);
    return $value > 0 ? $value : NULL;
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
