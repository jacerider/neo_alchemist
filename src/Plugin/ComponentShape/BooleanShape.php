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
  prop: 'boolean',
  label: new TranslatableMarkup('Boolean'),
  default_field_type: 'boolean',
  default_field_widget: 'boolean_checkbox',
)]
class BooleanShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  public function getValue(): bool {
    return (bool) parent::getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = [(bool) $values['value']];
    return parent::massageFormValues($values, $original_values, $form, $form_state);
  }

}
