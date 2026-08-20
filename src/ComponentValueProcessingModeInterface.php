<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Marks a value provider whose stop/continue behavior the site builder picks.
 *
 * A value provider implementing this interface exposes a standard "Processing"
 * select in its configuration form, and the value pipeline consults the chosen
 * mode (together with the shape's emptiness test) to decide whether to claim
 * the value — instead of the plugin hard-coding that decision.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
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
