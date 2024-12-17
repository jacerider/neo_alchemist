<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'image',
  label: new TranslatableMarkup('Image'),
)]
class ImageShape extends ObjectShape {

  /**
   * {@inheritDoc}
   */
  public function allowExpanded(): bool {
    return FALSE;
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
      // ksm($fieldDefinition->getType(), $entityFieldDefinition->getType(), $entityFieldDefinition->getName(), $entityFieldDefinition->getSetting('target_type'));.
      if ($entityFieldDefinition->getSetting('target_type') === 'media') {
        $target_bundles = $entityFieldDefinition->getSetting('handler_settings')['target_bundles'] ?? [];
        if (count($target_bundles) === 1 && isset($target_bundles['image'])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
