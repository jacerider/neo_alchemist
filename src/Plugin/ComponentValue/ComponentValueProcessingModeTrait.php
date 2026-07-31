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
 * The mode also decides what an EMPTY result means, because getDefaultValue()
 * keeps the value threaded into a producer that came up empty without claiming.
 * stop_when_found and continue therefore say "I found nothing, carry on with
 * what you had" — for an untouched prop, the component's schema example.
 * Only a claim (block, or a veto) says "nothing IS the answer", so block is the
 * mode for a prop whose examples are editor scaffolding rather than a default.
 *
 * **Scope: the mode governs the provider search, and nothing else.**
 * applyProcessingMode() is called from exactly one place,
 * ComponentShapePluginBase::getDefaultValue(). That is not a narrow reach that
 * someone forgot to widen — it is the only pass where the mode's question even
 * applies. The shape iterates its value plugins in nine places, and only that
 * one is a SEARCH, where "which plugin wins" is a decision the site builder
 * should get to make. The rest are broadcasts (onShapeInit, isEditable, the
 * form hooks) or chains (modifyValue, both alterValue loops), where every
 * plugin is meant to get a turn. A claim never leaks between them:
 * ComponentShapePluginCollection::getAllowedInstances() resets the flag on
 * every call.
 *
 * Two passes look like candidates for widening. Neither is:
 *
 * - **The modifier pass** would be actively destructive. Every pass walks one
 *   shared instance list sorted providers → fallback → modifiers → settings,
 *   and a provider takes part in the modifier loop too (its modifyValue() is
 *   the base class's identity). Consulting the mode there would let a provider
 *   in stop_when_found — the DEFAULT — break the loop at its own position, so
 *   prefix, suffix, token and formatted_text would silently stop running on
 *   every prop where a provider found a value.
 * - **The override pass** (provideOverrideValue() in init()) carries the value
 *   a person authored, not a provider's answer. Letting a provider's mode claim
 *   there would mean "Always stop" suppressing typed content. No shipped plugin
 *   implements that method today; one that does must raise its own claim and
 *   own that decision explicitly.
 *
 * @see \Drupal\neo_alchemist\ComponentValueProcessingModeInterface
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProcessingModeIntegrationTest
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProcessingModeScopeTest
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
      '#description' => $this->t('What happens after this provider runs, while the providers on this prop are searched for a value. "Stop when a value is found" lets later providers fill in when this one is empty. "Provide, allow later changes" always lets later providers change the value. "Always stop" halts the search even when empty — pick it when an empty source must render nothing, such as a list, menu or image whose example is only a placeholder for the editor. With either of the other two, a provider that finds nothing leaves the value alone, so a prop whose source field is empty falls back to the component\'s own example. Modifiers such as prefix and format still run afterwards in every case, and an authored value still wins.'),
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
