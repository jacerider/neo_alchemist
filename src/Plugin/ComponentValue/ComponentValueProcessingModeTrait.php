<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;

/**
 * Adds the standard, site-builder-configurable "Processing" mode to a provider.
 *
 * Implements ComponentValueProcessingModeInterface for a value provider that
 * extends ComponentValuePluginBase. The provider just produces its value; this
 * trait exposes the mode select and lets the pipeline decide whether to claim.
 *
 * @see \Drupal\neo_alchemist\ComponentValueProcessingModeInterface
 */
trait ComponentValueProcessingModeTrait {

  /**
   * The default configuration for the processing mode.
   *
   * Merge into the plugin's defaultConfiguration().
   *
   * @return array
   *   The processing mode default configuration.
   */
  protected function processingModeDefaultConfiguration(): array {
    return ['processing_mode' => $this->processingModeDefault()];
  }

  /**
   * The default mode for this provider.
   *
   * Override to change the out-of-the-box behavior for a specific provider.
   *
   * @return string
   *   One of the ComponentValueProcessingModeInterface::MODE_* constants.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND;
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessingMode(): string {
    return $this->configuration['processing_mode'] ?? $this->processingModeDefault();
  }

  /**
   * The available processing mode options.
   *
   * @return array
   *   Mode labels keyed by mode machine name.
   */
  protected function processingModeOptions(): array {
    return [
      ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND => $this->t('Stop when a value is found'),
      ComponentValueProcessingModeInterface::MODE_CONTINUE => $this->t('Provide, allow later changes'),
      ComponentValueProcessingModeInterface::MODE_BLOCK => $this->t('Always stop (block if empty)'),
    ];
  }

  /**
   * Adds the standard "Processing" select to the provider's config form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array with the processing mode select added.
   */
  protected function buildProcessingModeForm(array $form, FormStateInterface $form_state): array {
    $form['processing_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Processing'),
      '#description' => $this->t('What happens after this provider runs. "Stop when a value is found" lets later providers fill in when this one is empty. "Provide, allow later changes" always lets later providers change the value. "Always stop" halts even when empty, so nothing renders.'),
      '#options' => $this->processingModeOptions(),
      '#default_value' => $this->getProcessingMode(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function applyProcessingMode(mixed $value): void {
    // Respect an explicit claim the plugin already raised (e.g. a veto).
    if ($this->hasClaimedValue()) {
      return;
    }
    $mode = $this->getProcessingMode();
    if ($mode === ComponentValueProcessingModeInterface::MODE_CONTINUE) {
      return;
    }
    if ($mode === ComponentValueProcessingModeInterface::MODE_BLOCK) {
      $this->claimValue();
      return;
    }
    // stop_when_found (default): claim only when a value was produced.
    if (!$this->shape->isProvidedValueEmpty($value)) {
      $this->claimValue();
    }
  }

}
