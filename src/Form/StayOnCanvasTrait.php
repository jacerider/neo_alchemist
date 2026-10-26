<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageToastCommand;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Keeps the editor open after a draft-level confirmation (Save, Revert).
 *
 * Opened from the canvas header in a dialog, these forms used to redirect to
 * the entity, which ended the editing session for actions whose point is to
 * carry on. Submitted through the dialog they now close it, refresh the
 * previews and the header's save group, and say what happened in a toast. A
 * submit that is not an AJAX one (the form opened as a page) still redirects
 * and uses the messenger.
 *
 * The using form keeps the field item in $this->fieldItem and reports through
 * reportOutcome() from submitForm().
 *
 * @internal
 */
trait StayOnCanvasTrait {

  use ComponentAjaxFormHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    if ($this->isAjax()) {
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    $actions['cancel']['#attributes']['data-neo-modal-close'] = '1';
    return $actions;
  }

  /**
   * Says what the submit did: a toast in the editor, else a status message.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Component\Render\MarkupInterface|string $toast
   *   The short toast for the editor ("Saved").
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   The full message for the page the form redirects to.
   */
  protected function reportOutcome(FormStateInterface $form_state, $toast, $message): void {
    if ($this->isAjax()) {
      $form_state->set('neo_alchemist_toast', $toast);
      return;
    }
    $this->messenger()->addStatus($message);
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    // A refusal found while committing (a DraftConflictException) rebuilds the
    // form with the reason in the messenger. It stays in the dialog, the same
    // as one found during validation.
    if ($form_state->isRebuilding()) {
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      $form['#sorted'] = FALSE;
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $form['#attributes']['data-drupal-selector'] . '"]', $form));
      return $response;
    }
    $response = $this->getComponentReponse(ComponentManageHelper::getId($this->fieldItem), NULL, $this->fieldItem);
    if ($toast = $form_state->get('neo_alchemist_toast')) {
      $response->addCommand(new InstanceComponentManageToastCommand($toast));
    }
    return $response;
  }

}
