<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'style',
  label: new TranslatableMarkup('Style'),
  default_field_type: 'list_string',
  default_field_widget: 'options_select',
)]
class StyleShape extends StyleShapeBase {

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): ?array {
    if (array_key_exists('styles', $this->schema)) {
      return array_map(function ($style) {
        return $style['label'] ?? 'Unnamed';
      }, $this->schema['styles']);
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getPropValue(): Attribute {
    $originalValue = parent::getPropValue();
    $value = new Attribute();
    if ($originalValue && isset($this->schema['styles'][$originalValue]['value'])) {
      $value->addClass($this->schema['styles'][$originalValue]['value']);
    }
    return $value;
  }

}
