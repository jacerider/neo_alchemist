<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 */
#[ComponentValue(
  id: 'number',
  label: new TranslatableMarkup('Format as Number'),
  description: new TranslatableMarkup('Convert value to number.'),
  group: 'Modifiers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
)]
final class NumberValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'decimals' => 0,
      'decimal_separator' => '.',
      'thousands_separator' => ',',
    ];
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function modifierForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['decimals'] = [
      '#type' => 'number',
      '#title' => $this->t('Decimals'),
      '#description' => $this->t('Sets the number of decimal digits. If 0, the decimal separator is omitted from the return value.'),
      '#default_value' => $this->configuration['decimals'],
      '#required' => TRUE,
    ];
    $form['decimal_separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decimal Separator'),
      '#description' => $this->t('Sets the separator for the decimal point.'),
      '#default_value' => $this->configuration['decimal_separator'],
    ];
    $form['thousands_separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Thousands Separator'),
      '#description' => $this->t('Sets the thousands separator.'),
      '#default_value' => $this->configuration['thousands_separator'],
    ];
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function modifierValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('decimals', (int) $form_state->getValue('decimals'));
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    return number_format((float) $value, (int) $this->configuration['decimals'], $this->configuration['decimal_separator'], $this->configuration['thousands_separator']);
  }

}
