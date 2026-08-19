<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * A form that stages a list of configured plugins before saving.
 *
 * The mold three component admin forms are built from. Its contract is the
 * transition vocabulary below plus one rule: **every mutation happens in
 * `validateForm()` and is staged on the form object's own unsaved entity**, so
 * nothing persists until Save and an AJAX rebuild resumes from the staged
 * state rather than from storage.
 *
 * The staging operations each implementation performs during that pass are:
 *
 * - *list* the staged items — the summary rows a site builder reorders;
 * - *add* one, which activates it and opens its edit pane;
 * - *remove* one;
 * - *commit* the open pane, validating and massaging the plugin's settings;
 * - *stage* the result on the entity.
 *
 * They are not methods on this interface because the adapters address an item
 * differently and honestly so: the slot form by uuid, because a slot may hold
 * two of the same plugin; the prop form by plugin id within a shape × group
 * section, because a shape holds each provider at most once and one form
 * carries many such sections. A single signature covering both would be a
 * composite key that neither form uses. What IS shared — the vocabulary, the
 * elements, and the limited-submission rule — lives in
 * StagedPluginListTrait, so a fix there reaches every adapter.
 *
 * @see \Drupal\neo_alchemist\Form\StagedPluginListTrait
 */
interface StagedPluginListInterface {

  /**
   * Show the list of staged items.
   */
  const OP_LIST = 'list';

  /**
   * Add an item and open its edit pane.
   */
  const OP_ADD = 'add';

  /**
   * Open an existing item's edit pane.
   */
  const OP_EDIT = 'edit';

  /**
   * Commit the open edit pane and return to the list.
   */
  const OP_UPDATE = 'update';

  /**
   * Drop an item from the staged list.
   */
  const OP_REMOVE = 'remove';

  /**
   * Close the open edit pane without committing it.
   */
  const OP_CANCEL = 'cancel';

  /**
   * Submit handler for every op button: rebuild with the new op state.
   *
   * The transition itself is performed in `validateForm()`, where the staged
   * entity lives; this only tells the form builder to render it again.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitRebuild(array $form, FormStateInterface $form_state): void;

}
