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
  prop: 'integer',
  label: new TranslatableMarkup('Integer'),
  default_field_type: 'integer',
  default_field_type_with_options: 'list_integer',
  default_field_widget: 'number',
  default_field_widget_with_options: 'options_select',
)]
class IntegerShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  public function getValue(): float {
    return (int) parent::getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): array {
    // Converty value to proper type.
    $values = array_map(function ($v) {
      $v['value'] = (int) $v['value'];
      return $v;
    }, $values);
    return parent::massageFormValues($values, $original_values, $form, $form_state);
  }

}
