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
)]
class BooleanShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'boolean';
  }

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    return 'boolean_checkbox';
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): bool {
    return (bool) parent::getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    $values = [(bool) $values['value']];
    return parent::massageFormValues($form, $form_state, $values);
  }

}
