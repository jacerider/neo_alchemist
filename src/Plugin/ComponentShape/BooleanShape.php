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
  public function init(): self {
    $this->getOptionEmpty()->setAccess(FALSE, 'Boolean shapes cannot be empty.');
    return parent::init();
  }

  /**
   * {@inheritDoc}
   */
  public function buildValue(): bool {
    return (bool) parent::buildValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    $values = parent::massageFormValues($values, $original_values, $form, $form_state);
    if (isset($values['value'])) {
      $values['value'] = (bool) $values['value'];
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
    return 'true';
  }

}
