<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Form\LimitedSubmissionTrait;

/**
 * Exposes the limited-submission predicate for a unit test.
 *
 * The rule belongs to a trait so the forms inherit it rather than each knowing
 * it; a probe is how it is driven without building a form.
 */
final class LimitedSubmissionProbe {

  use LimitedSubmissionTrait;

  /**
   * Answers the predicate.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   Whether the triggering element limited validation.
   */
  public function ask(FormStateInterface $form_state): bool {
    return $this->isLimitedSubmission($form_state);
  }

}
