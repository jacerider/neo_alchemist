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
  default_field_type: 'float',
  default_field_type_with_options: 'list_float',
  default_field_widget: 'number',
  default_field_widget_with_options: 'options_select',
)]
class NumberShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  public function buildValue(): float {
    return (float) parent::buildValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = parent::massageFormValues($values, $original_values, $form, $form_state);
    if (isset($values['value'])) {
      $values['value'] = (int) $values['value'];
    }
    return $values;
  }

  /**
   * Get default examples for this shape.
   *
   * @return mixed
   *   The default examples for this shape.
   */
  public static function getGenerationExamples(array $prop) {
    return '100';
  }

}
