<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides a trait for ConfigComponentTreeConstraintValidator.
 */
trait ConfigComponentTreeTrait {

  /**
   * Conjure a field item object.
   *
   * @param array{tree: string, props: string} $value
   *   The value to conjure.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   *   The field item object.
   */
  private function conjureFieldItemObject(array $value): ComponentTreeItem {
    assert($this->typedDataManager instanceof TypedDataManagerInterface);
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:component_tree');
    $field_item = $this->typedDataManager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    $field_item->setValue($value);
    assert($field_item instanceof ComponentTreeItem);
    return $field_item;
  }

}
