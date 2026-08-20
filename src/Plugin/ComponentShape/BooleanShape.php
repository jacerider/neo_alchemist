<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

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
  public function init(): ComponentShapePluginInterface {
    $this->getOptionEmpty()->setAccess(FALSE, 'Boolean shapes cannot be empty.');
    return parent::init();
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(): mixed {
    return $this->castScalar(parent::buildValue());
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
   * go through here, so they cannot drift into disagreeing about what a boolean
   * prop holds.
   *
   * @param mixed $value
   *   The value to cast.
   *
   * @return bool
   *   The value as a bool.
   */
  protected function castScalar(mixed $value): bool {
    return (bool) $value;
  }

  /**
   * Get default examples for this shape.
   *
   * @return mixed
   *   The default examples for this shape.
   */
  public static function getGenerationExamples(array $prop) {
    return 'true';
  }

}
