<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\neo_alchemist\Plugin\DataTypeOverride\TextProcessedOverride;
use Drupal\neo_alchemist\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\text\Plugin\Field\FieldType\TextItem;

/**
 * @todo Fix upstream.
 */
class TextItemOverride extends TextItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['processed']
      ->setClass(TextProcessedOverride::class)
      ->addConstraint('StringSemantics', StringSemanticsConstraint::MARKUP);
    return $properties;
  }

}
