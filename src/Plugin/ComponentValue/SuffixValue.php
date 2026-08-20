<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 */
#[ComponentValue(
  id: 'suffix',
  label: new TranslatableMarkup('Suffix'),
  description: new TranslatableMarkup('Provide a suffix to the value.'),
  group: 'modifiers',
  inline: TRUE,
  prop_types: [
    ComponentShapePluginInterface::STRING,
    ComponentShapePluginInterface::INTEGER,
    ComponentShapePluginInterface::NUMBER,
  ],
  weight: 10,
)]
final class SuffixValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'value' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    if ($this->configuration['value'] === '') {
      return [];
    }
    return [$this->t('“%value”', ['%value' => $this->configuration['value']])];
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix'),
      '#description' => $this->t('The suffix to add to the value.'),
      '#default_value' => $this->configuration['value'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    return $value . $this->configuration['value'];
  }

}
