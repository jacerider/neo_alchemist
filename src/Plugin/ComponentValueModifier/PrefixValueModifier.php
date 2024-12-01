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
  id: 'prefix',
  label: new TranslatableMarkup('Prefix'),
  description: new TranslatableMarkup('Provide a prefix to the value.'),
  prop_types: [
    ComponentShapePluginInterface::STRING,
    ComponentShapePluginInterface::INTEGER,
    ComponentShapePluginInterface::NUMBER,
  ],
  weight: 10,
)]
final class PrefixValueModifier extends ComponentValueModifierPluginBase {

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
      '#title' => $this->t('Prefix'),
      '#description' => $this->t('The prefix to add to the value.'),
      '#default_value' => $this->configuration['value'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    return $this->configuration['value'] . $value;
  }

}
