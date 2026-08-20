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
 * The mode also decides what an EMPTY result means, because the provider search
 * keeps the value threaded into a producer that came up empty without claiming.
 * stop_when_found and continue therefore say "I found nothing, carry on with
 * what you had" — for an untouched prop, the component's schema example.
 * Only a claim (block, or a veto) says "nothing IS the answer", so block is the
 * mode for a prop whose examples are editor scaffolding rather than a default.
 *
 * **Scope: the mode governs the provider search, and nothing else.**
 * claimsValue() is consulted from exactly one place, ValueProviderSearch. That
 * is not a narrow reach that someone forgot to widen — it is the only pass
 * where the mode's question even applies. The shape iterates its value plugins
 * in several places, and only that one is a SEARCH, where "which plugin wins"
 * is a decision the site builder should get to make. The rest are broadcasts
 * (onShapeInit, isEditable, the form hooks) or chains (modifyValue, both
 * alterValue loops), where every plugin is meant to get a turn. The mode cannot
 * leak between them: claimsValue() is a pure question that mutates nothing, and
 * a producer's outcome (ComponentValueProvision) is recomputed on each pass.
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
      ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND => $this->t('Use its value and stop'),
      ComponentValueProcessingModeInterface::MODE_BLOCK => $this->t('Always use its value — final'),
      ComponentValueProcessingModeInterface::MODE_CONTINUE => $this->t('Add its value and continue'),
    ];
  }

  /**
   * A short badge-style description of the configured mode.
   *
   * Used in provider lists and section badges so the chain semantics are
   * visible without opening the provider's form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The mode summary.
   */
  public function processingModeSummary() {
    return match ($this->getProcessingMode()) {
      ComponentValueProcessingModeInterface::MODE_BLOCK => $this->t('always claims — final'),
      ComponentValueProcessingModeInterface::MODE_CONTINUE => $this->t('adds and continues'),
      default => $this->t('stops when it finds a value'),
    };
  }

  /**
   * Adds the standard "Processing" radios to the provider's config form.
   *
   * Rendered first in the provider's form (weight -10): the chain behavior is
   * the one setting that decides how this provider interacts with the others
   * on the prop, so it must not be buried below the provider's own settings.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array with the processing mode radios added.
   */
  protected function buildProcessingModeForm(array $form, FormStateInterface $form_state): array {
    $form['processing_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('When this provider runs'),
      '#options' => $this->processingModeOptions(),
      '#default_value' => $this->getProcessingMode(),
      '#neo_size' => 'sm',
      '#weight' => -10,
      ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND => [
        '#description' => $this->t('If it finds nothing, later providers may still fill in.'),
      ],
      ComponentValueProcessingModeInterface::MODE_BLOCK => [
        '#description' => $this->t('Halts the search even when empty — an empty source renders nothing instead of the example.'),
      ],
      ComponentValueProcessingModeInterface::MODE_CONTINUE => [
        '#description' => $this->t('Later providers may still change the value.'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function claimsValue(mixed $value): bool {
    return match ($this->getProcessingMode()) {
      // Never claim: a later provider may still change the value.
      ComponentValueProcessingModeInterface::MODE_CONTINUE => FALSE,
      // Always claim, even when the produced value is empty.
      ComponentValueProcessingModeInterface::MODE_BLOCK => TRUE,
      // stop_when_found (default): claim only when a value was produced.
      default => !$this->shape->isProvidedValueEmpty($value),
    };
  }

}
