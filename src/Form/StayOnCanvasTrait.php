<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageToastCommand;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Ends a draft-level confirmation (Save, Revert) opened from the canvas.
 *
 * Submitted through the dialog, a form either stays on the canvas or leaves
 * it. Staying (reportOutcome()) closes the dialog, refreshes the previews and
 * the header's save group, and says what happened in a toast: Revert does
 * this, because the point of discarding a draft is to carry on editing.
 * Leaving (leaveCanvas()) sends the browser to a page and says what happened
 * in a status message there: Save does this, because saving is how an editor
 * finishes. A submit that is not an AJAX one (the form opened as a page)
 * redirects either way and uses the messenger.
 *
 * The using form keeps the field item in $this->fieldItem and reports through
 * reportOutcome() or leaveCanvas() from submitForm().
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
   * Leaves the canvas for a page, saying what happened in a status message.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Url $url
   *   Where to go.
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   The status message for the page.
   */
  protected function leaveCanvas(FormStateInterface $form_state, Url $url, $message): void {
    $form_state->setRedirectUrl($url);
    if ($this->isAjax()) {
      $form_state->set('neo_alchemist_leave', $url->toString());
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
    if ($leave = $form_state->get('neo_alchemist_leave')) {
      $response = new AjaxResponse();
      $response->addCommand(new RedirectCommand($leave));
      return $response;
    }
    $response = $this->getComponentReponse(ComponentManageHelper::getId($this->fieldItem), NULL, $this->fieldItem);
    if ($toast = $form_state->get('neo_alchemist_toast')) {
      $response->addCommand(new InstanceComponentManageToastCommand($toast));
    }
    return $response;
  }

}
