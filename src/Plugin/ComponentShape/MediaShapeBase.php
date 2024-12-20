<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
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

}
