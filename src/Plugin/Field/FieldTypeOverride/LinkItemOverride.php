<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\neo_alchemist\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\link\Plugin\Field\FieldType\LinkItem;

/**
 * @todo Fix upstream.
 */
class LinkItemOverride extends LinkItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['title']->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE);
    return $properties;
  }

}
