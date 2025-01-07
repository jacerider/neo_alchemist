<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_modal\Ajax\NeoModalCloseCommand;

/**
 * Provides a helper to for submitting an AJAX form.
 *
 * @internal
 */
trait InstanceIframeHelper {

  use AjaxFormHelperTrait;

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
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentPreviewIframeCommand());
    $response->addCommand(new NeoModalCloseCommand());
    return $response;
  }

}
