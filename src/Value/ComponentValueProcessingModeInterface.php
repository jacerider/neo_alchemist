<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Core\Form\FormStateInterface;

/**
 * Marks a value provider whose stop/continue behavior the site builder picks.
 *
 * A value provider implementing this interface exposes a standard "Processing"
 * select in its configuration form, and the value pipeline consults the chosen
 * mode (together with the shape's emptiness test) to decide whether to claim
 * the value — instead of the plugin hard-coding that decision.
 *
 * Declaring this interface is all a producer does: ComponentValuePluginBase
 * merges processingModeDefaultConfiguration() into the plugin's configuration
 * and appends buildProcessingModeForm() to its config form. Those two methods
 * are part of the contract (not just the trait) so the base can call them on
 * any implementer, and so an implementer that forgot to satisfy them fails at
 * class-definition time rather than at form-render time. ComponentValue
 * ProcessingModeTrait is the ready-made implementation.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase
 */
interface ComponentValueProcessingModeInterface extends ComponentValuePluginInterface {

  /**
   * Claim a produced value; fall through to the next provider when empty.
   */
  const MODE_STOP_WHEN_FOUND = 'stop_when_found';

  /**
   * Never claim: provide a value that later providers may still change.
   */
  const MODE_CONTINUE = 'continue';

  /**
   * Always claim: halt after this provider even when it produced nothing.
   */
  const MODE_BLOCK = 'block';

  /**
   * The processing mode's contribution to the plugin's default configuration.
   *
   * ComponentValuePluginBase merges this into the plugin's configuration when
   * the plugin implements this interface, so a producer never appends it to its
   * own defaultConfiguration() by hand.
   *
   * @return array
   *   The processing mode default configuration.
   */
  public function processingModeDefaultConfiguration(): array;

  /**
   * Adds the standard "Processing" mode select to the plugin's config form.
   *
   * ComponentValuePluginBase calls this when the plugin implements this
   * interface, so a producer never calls it from its own configuration form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array with the processing mode select added.
   */
  public function buildProcessingModeForm(array $form, FormStateInterface $form_state): array;

  /**
   * Gets the configured processing mode.
   *
   * @return string
   *   One of the MODE_* constants.
   */
  public function getProcessingMode(): string;

  /**
   * Whether the configured mode claims the value the provider produced.
   *
   * A pure question the provider search asks after a producer offers a value:
   * `continue` never claims, `block` always claims, and the default
   * `stop_when_found` claims only when the produced value is non-empty. The
   * search only asks it when the producer did not already claim the value
   * itself, so this method does not need to account for an explicit claim.
   *
   * @param mixed $value
   *   The value the provider produced this pass.
   *
   * @return bool
   *   TRUE if the mode claims the value (halting the search), FALSE otherwise.
   */
  public function claimsValue(mixed $value): bool;

}
