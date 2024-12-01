<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueModifier;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueModifier;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueModifierPluginBase;

/**
 * Plugin implementation of the neo_component_value_modifier.
 */
#[ComponentValueModifier(
  id: 'suffix',
  label: new TranslatableMarkup('Suffix'),
  description: new TranslatableMarkup('Provide a suffix to the value.'),
  prop_types: [
    ComponentShapePluginInterface::STRING,
    ComponentShapePluginInterface::INTEGER,
    ComponentShapePluginInterface::NUMBER,
  ],
  weight: 10,
)]
final class SuffixValueModifier extends ComponentValueModifierPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'value' => '',
    ];
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function modifierForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
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
