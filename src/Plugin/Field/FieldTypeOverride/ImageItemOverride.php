<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\neo_alchemist\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\image\Plugin\Field\FieldType\ImageItem;

/**
 * @todo Fix upstream.
 */
class ImageItemOverride extends ImageItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['alt']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    $properties['title']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    return $properties;
  }

}
