<?php

namespace Drupal\neo_alchemist\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @todo This is naive and insufficient: this needs to take over the rendering of the entire entity, not just of this single field. Still, for PoC/data model purposes, this is sufficient initially.
 */
#[FieldFormatter(
  id: 'neo_component_tree',
  label: new TranslatableMarkup('Render components'),
  field_types: [
    'neo_component_tree',
  ],
)]
class NaiveComponentTreeFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    return [];
    assert($items->count() === 1);
    assert($items[0] instanceof ComponentTreeItem);
    // This field type is single-cardinality: delta 0 is rendered.
    return [0 => $items[0]->toRenderable()];
  }

}
