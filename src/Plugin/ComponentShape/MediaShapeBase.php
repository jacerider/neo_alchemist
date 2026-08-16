<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
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
  public function resolveValue(mixed $value): mixed {
    $value = parent::resolveValue($value);
    if (is_array($value)) {
      $value = $this->resolveMediaSrc($value);
    }
    return $value;
  }

  /**
   * Resolve a media value's `src`.
   *
   * `theme://<path>` resolves against the default theme, `component://<path>`
   * against this component's folder, and a bare relative path against the
   * component folder when the file exists. External URLs and already-resolved
   * paths are left unchanged, so this is safe to call more than once.
   *
   * @param array $value
   *   The media value (expects a `src` key).
   *
   * @return array
   *   The value with a resolved `src`.
   */
  protected function resolveMediaSrc(array $value): array {
    if (empty($value['src']) || !is_string($value['src'])) {
      return $value;
    }
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
  public function getPreviewPlaceholder(): mixed {
    // A media prop cannot carry an example in the .component.yml — the value is
    // a media entity, not something authorable in YAML — so borrow a real one
    // of a supported type. This shows the frontend dev the component rendering
    // with genuine media rather than a stand-in that behaves differently.
    $media = $this->loadPreviewMedia();
    if (!$media) {
      return NULL;
    }
    $this->addCacheableDependency($media);
    return $this->getValueFromMedia($media) ?: NULL;
  }

  /**
   * Loads a media entity to preview a required, empty media prop with.
   *
   * Picks the most recently created published media of a type this shape
   * accepts. Access is checked so a preview never surfaces media the viewer
   * could not otherwise see.
   *
   * @return \Drupal\media\MediaInterface|null
   *   A media entity, or NULL if the site has none of a supported type.
   */
  protected function loadPreviewMedia(): ?MediaInterface {
    $mediaTypes = $this->getSupportedMediaTypes();
    if (!$mediaTypes) {
      return NULL;
    }

    // Creating a first media entity of a supported type should make the preview
    // start working, so the emptiness of this query is itself cacheable state.
    $cacheability = new CacheableMetadata();
    foreach ($mediaTypes as $mediaType) {
      $cacheability->addCacheTags(['media_list:' . $mediaType]);
    }
    $this->addCacheableDependency($cacheability);

    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', $mediaTypes, 'IN')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }

    $media = $storage->load(reset($ids));
    return $media instanceof MediaInterface ? $media : NULL;
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

  /**
   * Value keys that mean this shape's field item holds authored content.
   *
   * Media shapes provide non-standard default values, so the field item can
   * carry a resolved value while the underlying field reports itself empty.
   * A shape declares which key carries the authored datum; anything else falls
   * through to the field's own emptiness check.
   *
   * @return string[]
   *   The value keys to treat as content.
   */
  protected function getMediaValueContentKeys(): array {
    return ['src'];
  }

  /**
   * {@inheritDoc}
   */
  protected function isFieldItemEmpty(): bool {
    $value = $this->fieldItem->getValue();
    foreach ($this->getMediaValueContentKeys() as $key) {
      if (!empty($value[$key])) {
        return FALSE;
      }
    }
    return parent::isFieldItemEmpty();
  }

}
