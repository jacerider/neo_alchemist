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
trait InstanceComponentPreviewIframeHelper {

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
    // $messages['status_messages'] = [
    //   '#type' => 'status_messages',
    //   '#weight' => -1000,
    // ];
    // $response = new AjaxResponse();
    // $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
    $response = new AjaxResponse();
    $response->addCommand(new InstanceComponentPreviewIframeCommand());
    $response->addCommand(new NeoModalCloseCommand());
    // $status_messages = ['#type' => 'status_messages'];
    // $messages = \Drupal::service('renderer')->renderRoot($status_messages);
    // ksm($messages);
    // If (!empty($messages)) {
    //   $response->addCommand(new PrependCommand('.your_selector', $messages));
    // }.
    return $response;
  }

}
