<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'number',
  label: new TranslatableMarkup('Number'),
)]
class NumberShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    if (array_key_exists('enum', $this->schema)) {
      return 'list_float';
    }
    return 'float';
  }

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    if (array_key_exists('enum', $this->schema)) {
      return 'options_select';
    }
    return 'number';
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
    $settings = [];
    $settings['min'] = $this->schema['minimum'] ?? (array_key_exists('exclusiveMinimum', $this->schema) ? $this->schema['exclusiveMinimum'] + 1 : '');
    $settings['max'] = $this->schema['maximum'] ?? (array_key_exists('exclusiveMaximum', $this->schema) ? $this->schema['exclusiveMaximum'] - 1 : '');
    return $settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): float {
    return (float) parent::getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    // Converty value to proper type.
    $values = array_map(function ($v) {
      $v['value'] = (float) $v['value'];
      return $v;
    }, $values);
    return parent::massageFormValues($form, $form_state, $values);
  }

}
