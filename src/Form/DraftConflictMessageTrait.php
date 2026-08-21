<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Round-trips the shared draft's version and surfaces a refused write.
 *
 * Optimistic conflict detection catches at edit time the collision an editor
 * used to discover at publish time: a save against a draft a colleague has
 * moved past is refused rather than silently overwriting their work. The two
 * draft-writing forms round-trip the loaded version through a hidden field
 * (draftVersionField()), refuse a stale save in the validation phase where a
 * form error is legal (surfaceDraftConflict()), and — because the store guard
 * is the authority — also carry the version into the write so a refusal that
 * slips through the validate→submit gap is caught as a message rather than a
 * silent overwrite (draftConflictMessage()). That is the resolution the spec
 * chose over any client-side merge, diff or keep-mine UI, so the whole
 * affordance is one message and a link.
 *
 * It leans on $this->entityTypeManager, to name the last editor, and the string
 * translation helper every EntityForm carries.
 */
trait DraftConflictMessageTrait {

  /**
   * The hidden field that round-trips the loaded shared-draft version.
   *
   * Its value is the draft version at the moment the form opened; a hidden
   * field keeps that loaded value across the form's own refreshes (submitted
   * input wins over #default_value on rebuild), so a refresh does not silently
   * advance it to a version the editor never saw.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $fieldItem
   *   The field item whose draft is being edited.
   *
   * @return array
   *   The hidden form element.
   */
  protected function draftVersionField(ComponentTreeItem $fieldItem): array {
    return [
      '#type' => 'hidden',
      '#default_value' => $fieldItem->draftVersion(),
    ];
  }

  /**
   * Records a refused write as a form-level error and rebuilds the form.
   *
   * Setting the error is what makes the AJAX callback re-render the form with
   * status messages instead of the success response, so the editor reads the
   * refusal rather than believing the save landed. Only legal during the
   * validation phase — the write-time refusal is surfaced by
   * draftConflictMessage() instead.
   *
   * @param \Drupal\neo_alchemist\EditorState\DraftConflictException $conflict
   *   The refusal the store would raise.
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $fieldItem
   *   The field item whose draft was contended, for the reload URL.
   * @param array $form
   *   The form, so the error attaches at form level.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function surfaceDraftConflict(DraftConflictException $conflict, ComponentTreeItem $fieldItem, array $form, FormStateInterface $form_state): void {
    $form_state->setError($form, $this->draftConflictMessage($conflict, $fieldItem));
    $form_state->setRebuild();
  }

  /**
   * Builds the refusal message: who changed the draft, and a reload offer.
   *
   * @param \Drupal\neo_alchemist\EditorState\DraftConflictException $conflict
   *   The refusal, carrying the last editor's user id.
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $fieldItem
   *   The field item whose draft was contended, for the reload URL.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message, with a reload link when the editor URL can be built.
   */
  protected function draftConflictMessage(DraftConflictException $conflict, ComponentTreeItem $fieldItem) {
    $editor = $this->draftConflictEditorName($conflict->getLastEditorUid());
    $reload = NULL;
    try {
      $reload = $fieldItem->toUrl()->toString();
    }
    catch (\Exception) {
      // No reload link when the editor URL cannot be built; the message still
      // tells the editor to reload.
    }
    if ($reload === NULL) {
      return $this->t('@editor changed this layout while you were working, so your save was not applied and their work is intact. Reload the editor to pick up their version, then reapply your change.', [
        '@editor' => $editor,
      ]);
    }
    return $this->t('@editor changed this layout while you were working, so your save was not applied and their work is intact. <a href=":reload">Reload the editor</a> to pick up their version, then reapply your change.', [
      '@editor' => $editor,
      ':reload' => $reload,
    ]);
  }

  /**
   * Resolves the last editor's display name, or a neutral label.
   *
   * @param int|null $uid
   *   The last editor's user id, or NULL when the store did not record one.
   *
   * @return string
   *   The display name, or a neutral label when there is no resolvable user.
   */
  protected function draftConflictEditorName(?int $uid): string {
    if (!$uid) {
      return (string) $this->t('Another editor');
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user ? $user->getDisplayName() : (string) $this->t('Another editor');
  }

}
