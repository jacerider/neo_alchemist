<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentShape;

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
   */
  public function getDefaultSchemaValue(): mixed {
    $value = parent::getDefaultSchemaValue();
    if (!empty($value['src'])) {
      $isExternal = UrlHelper::isExternal($value['src']);
      if (!$isExternal) {
        $definition = $this->getComponent()->getComponentDefinition();
        $path = str_replace(DRUPAL_ROOT, '', $definition['path']) . '/' . ltrim($value['src'], '/');
        if (file_exists(ltrim($path, '/'))) {
          $value['src'] = $path;
        }
      }
    }
    return $value;
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
    $fid = $source->getSourceFieldValue($media);
    if (!$fid) {
      return [];
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if ($file instanceof FileInterface) {
      return [
        'src' => $file->createFileUrl(),
        'uri' => $file->getFileUri(),
        'alt' => $source->getMetadata($media, 'thumbnail_alt_value'),
        'width' => $source->getMetadata($media, 'width'),
        'height' => $source->getMetadata($media, 'height'),
        'target_id' => $media->id(),
      ];
    }
    return [];
  }

}
