<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

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
  public function getSupportedMediaTypes(): array {
    return ['image'];
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
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if ($file instanceof FileInterface) {
      return [
        'src' => $file->createFileUrl(),
        'alt' => $source->getMetadata($media, 'thumbnail_alt_value'),
        'width' => $source->getMetadata($media, 'width'),
        'height' => $source->getMetadata($media, 'height'),
        'target_id' => $media->id(),
      ];
    }
    return [];
    // $entity = $this->getFieldItem()->entity;
    // if ($entity instanceof MediaInterface) {
    //   $source = $entity->getSource();
    //   $fid = $source->getSourceFieldValue($entity);
    //   $file = $this->entityTypeManager->getStorage('file')->load($fid);
    //   if ($file instanceof FileInterface) {
    //     $value = [
    //       'src' => $file->createFileUrl(),
    //       'alt' => $source->getMetadata($entity, 'thumbnail_alt_value'),
    //       'width' => $source->getMetadata($entity, 'width'),
    //       'height' => $source->getMetadata($entity, 'height'),
    //       'target_id' => $entity->id(),
    //     ];
    //     $valueProvider->stopFurtherProcessing();
    //   }
    // }
    // else {
    //   if (!$this->isOptionDefault()) {
    //     $this->setOptionEmpty(TRUE);
    //     $valueProvider->stopFurtherProcessing();
    //   }
    // }
    // return $value;
  }

}
