<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\UrlHelper;
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
   */
  public function getDefaultSchemaValue(): mixed {
    $value = parent::getDefaultSchemaValue();
    if (!empty($value['src'])) {
      // If string starts with 'theme://' it should point to the theme.
      if (str_starts_with($value['src'], 'theme://')) {
        $themeHandler = \Drupal::service('theme_handler');
        $defaultTheme = \Drupal::config('system.theme')->get('default');
        $themePath = $themeHandler->getTheme($defaultTheme)->getPath();
        $imagePath = $themePath . '/' . str_replace('theme://', '', $value['src']);
        // Generate absolute URL for the image.
        $value['src'] = \Drupal::service('file_url_generator')->generateString($imagePath);
      }
      if (str_starts_with($value['src'], 'component://')) {
        $imagePath = $this->getComponent()->getPath() . '/' . str_replace('component://', '', $value['src']);
        // Generate absolute URL for the image.
        $value['src'] = \Drupal::service('file_url_generator')->generateString($imagePath);
      }
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
    $file = NULL;
    $source = $media->getSource();
    $fid = $source->getSourceFieldValue($media);
    if ($fid) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
    }
    else {
      // Sometimes we have a source field that contains a non-saved file.
      $config = $source->getConfiguration();
      if (!empty($config['source_field']) && $media->hasField($config['source_field'])) {
        $entity = $media->get($config['source_field'])->entity;
        if ($entity instanceof FileInterface) {
          $file = $entity;
        }
      }
    }
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
