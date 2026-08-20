<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
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
  protected function buildValue(?Attribute $renderAttributes = NULL): mixed {
    return $this->castScalar(parent::buildValue($renderAttributes));
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = parent::massageFormValues($values, $original_values, $form, $form_state);
    if (isset($values['value'])) {
      $values['value'] = $this->castScalar($values['value']);
    }
    return $values;
  }

  /**
   * Casts a value to the PHP type this shape stores.
   *
   * The single home for the cast: the render path and the form-save path both
   * go through here, so they cannot drift into disagreeing about what a integer
   * prop holds.
   *
   * @param mixed $value
   *   The value to cast.
   *
   * @return int
   *   The value as an int.
   */
  protected function castScalar(mixed $value): int {
    return (int) $value;
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
