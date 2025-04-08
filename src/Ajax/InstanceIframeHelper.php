<?php

namespace Drupal\neo_alchemist\Ajax;

use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
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
      $response->addCommand(new InstanceComponentManageIframeCommand('#' . $manageId . ' iframe'));
    }
    if ($draftId = $form_state->get('neo_component_uuid')) {
      $response->addCommand(new InstanceComponentManageForcusCommand($draftId));
    }
    $response->addCommand(new NeoModalCloseCommand());
    if ($form_state->get('neo_component_form')) {
      $instance = $form_state->get('neo_component_instance');
      if ($instance instanceof ComponentInstanceInterface) {
        $selector = '#' . ComponentManageHelper::getId($instance->getFieldItem()) . ' .neo-alchemist-manage--top-start';
        $response->addCommand(new HtmlCommand($selector, ComponentManageHelper::buildDynamicOperations($instance->getFieldItem())));
      }
      elseif ($instance instanceof ComponentTreeItem) {
        $selector = '#' . ComponentManageHelper::getId($instance) . ' .neo-alchemist-manage--top-start';
        $response->addCommand(new HtmlCommand($selector, ComponentManageHelper::buildDynamicOperations($instance)));
      }
    }
    return $response;
  }

}
