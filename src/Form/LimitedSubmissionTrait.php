<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Owns the one rule about limited-validation submissions.
 *
 * Nine files in this module set `#limit_validation_errors`; exactly one stated
 * the rule for reading it back, and the forms that did not know it survived on
 * luck. It lives here so a form cannot omit it by not knowing about it.
 *
 * @see \Drupal\neo_alchemist\Form\StagedPluginListTrait
 */
trait LimitedSubmissionTrait {

  /**
   * Whether the submission limited its validation to part of the form.
   *
   * Drupal's Button element defaults `#limit_validation_errors` to FALSE,
   * meaning "do not limit" — so a genuinely limited submission is the one
   * whose value is an ARRAY. A plain presence check classifies every button,
   * Save included, as limited, and every commit path guarded by it silently
   * stops running while the form still reports success.
   *
   * A limited trigger submits no usable values, so a form must run its state
   * transition and nothing else: committing from an empty value set wipes
   * whatever the site builder had staged.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when the triggering element limited validation.
   *
   * @see \Drupal\Core\Render\Element\Button::getInfo()
   */
  protected function isLimitedSubmission(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    return $trigger !== NULL && is_array($trigger['#limit_validation_errors'] ?? FALSE);
  }

}
