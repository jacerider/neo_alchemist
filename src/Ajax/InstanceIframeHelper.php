<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
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
    if ($manageId = $form_state->get('neo_component_manage_id')) {
      $response->addCommand(new InstanceComponentPreviewIframeCommand('#' . $manageId . ' iframe'));
    }
    $response->addCommand(new NeoModalCloseCommand());
    if ($form_state->get('neo_component_form')) {
      $instance = $form_state->get('neo_component_instance');
      if ($instance) {
        $selector = '#' . ComponentManageHelper::getId() . ' .neo-alchemist-manage--top-start';
        $response->addCommand(new HtmlCommand($selector, ComponentManageHelper::buildDynamicOperations($instance->getFieldItem())));
      }
    }
    return $response;
  }

}
