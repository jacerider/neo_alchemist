<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'string',
  label: new TranslatableMarkup('String'),
)]
class StringShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    if (array_key_exists('enum', $this->schema)) {
      return 'list_string';
    }
    return 'string';
  }

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    if (array_key_exists('enum', $this->schema)) {
      return 'options_select';
    }
    return 'string_textfield';
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldStorageSettings(): array {
    if (array_key_exists('enum', $this->schema)) {
      return [
        'allowed_values' => array_map(fn ($v) => [
          'value' => $v,
          'label' => (string) $v,
        ], $this->schema['enum'])
      ];
    }
    return [];
  }

}
