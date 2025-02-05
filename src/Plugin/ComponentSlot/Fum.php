<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentSlotPluginBase;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'fum',
  label: new TranslatableMarkup('Fum'),
  description: new TranslatableMarkup('Fum description.'),
)]
final class Fum extends ComponentSlotPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'test' => 'TEST',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Test: @test', ['@test' => $this->configuration['test']]);
    $summary[] = $this->t('Magic');
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test'),
      '#default_value' => $this->configuration['test'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    return [
      '#type' => 'container',
      'markup' => [
        '#markup' => $this->configuration['test'],
      ],
    ];
  }

}
