<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;

/**
 * A trait for adding the module handler.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class MediaShapeBase extends ObjectShape implements ComponentShapeMediaPluginInterface {

  /**
   * {@inheritDoc}
   */
  abstract public function getSupportedMediaTypes(): array;

  /**
   * {@inheritDoc}
   */
  public function allowExpanded(): bool {
    return FALSE;
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
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    $fieldDefinition = $this->getFieldItemList()->getFieldDefinition();
    if ($fieldDefinition->getType() === $entityFieldDefinition->getType()) {
      if ($entityFieldDefinition->getSetting('target_type') === 'media') {
        $targetBundles = $entityFieldDefinition->getSetting('handler_settings')['target_bundles'] ?? [];
        $supportedMediaTypes = $this->getSupportedMediaTypes();
        if (count($targetBundles) !== count($supportedMediaTypes)) {
          return FALSE;
        }
        if (array_diff($targetBundles, $supportedMediaTypes)) {
          return FALSE;
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function getValueFromMedia(MediaInterface $media): array {
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function getFileFromMedia(MediaInterface $media): FileInterface|null {
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
    return $file;
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultPreview(): ?array {
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function buildMediaOverrideForm(array $form, FormStateInterface $form_state, ?MediaInterface $media = NULL): array {
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function massageMediaOverrideValues(array &$values, array $override_values, array $original_values, array $form, FormStateInterface $form_state): void {
    $values += $override_values;
  }

}
