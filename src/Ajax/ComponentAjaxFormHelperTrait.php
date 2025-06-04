<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a helper to for submitting an AJAX form.
 *
 * @internal
 */
trait ComponentAjaxFormHelperTrait {

  use AjaxFormHelperTrait;
  use ComponentAjaxHelperTrait;

  /**
   * Allows the form to respond to a successful AJAX submission.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response.
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return $this->getComponentReponse(
      $form_state->get('neo_component_manage_id'),
      $form_state->get('neo_component_uuid'),
      $form_state->get('neo_component_instance')
    );
  }

}
