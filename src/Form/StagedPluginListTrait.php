<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * The shared mechanics of the list↔edit state machine.
 *
 * The architecture documentation says the prop form is "a list↔edit state
 * machine in the mold of the slot form". That mold was not a module — it was a
 * shape both forms independently re-derived: op state in the form state, a
 * draggable summary table, an add-plugin select, an edit pane with update and
 * cancel, mutation performed inside `validateForm()`, staging on a cached
 * unsaved entity, and an AJAX rebuild. Two implementations, different names for
 * the same concepts, and the drift already visible.
 *
 * This trait owns the parts that are genuinely one thing: the
 * limited-submission rule, the op buttons, the weight column, the add select
 * and the edit pane's actions. The vocabulary they speak is declared on
 * StagedPluginListInterface, which every using form implements.
 *
 * @see \Drupal\neo_alchemist\Form\StagedPluginListInterface
 */
trait StagedPluginListTrait {

  use LimitedSubmissionTrait;

  /**
   * Builds one of the list's op buttons.
   *
   * @param string $op
   *   One of StagedPluginListInterface's OP_* constants.
   * @param string $name
   *   The element name, unique within the form.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The button label.
   * @param array $meta
   *   The op's own properties — which item it addresses. Merged in as-is, so
   *   each form keeps the property names its validateForm() reads.
   * @param array $ajax
   *   The '#ajax' definition rebuilding this form.
   * @param bool $limited
   *   Whether the button submits with validation limited to nothing. TRUE for
   *   any op that only transitions state; see ::isLimitedSubmission().
   *
   * @return array
   *   A submit element.
   */
  protected function stagedOpButton(string $op, string $name, $label, array $meta, array $ajax, bool $limited = TRUE): array {
    $element = [
      '#type' => 'submit',
      '#name' => $name,
      '#value' => $label,
      '#op' => $op,
      '#submit' => ['::submitRebuild'],
      '#ajax' => $ajax,
    ] + $meta;
    if ($limited) {
      $element['#limit_validation_errors'] = [];
    }
    return $element;
  }

  /**
   * Builds a list row's weight control.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The control's title, shown only to screen readers.
   * @param int $weight
   *   The row's position.
   * @param string $group
   *   The tabledrag group class shared with the table's #tabledrag entry.
   *
   * @return array
   *   A weight element.
   */
  protected function stagedWeightCell($title, int $weight, string $group): array {
    return [
      '#type' => 'weight',
      '#title' => $title,
      '#title_display' => 'invisible',
      '#default_value' => $weight,
      '#attributes' => [
        'class' => [$group],
      ],
    ];
  }

  /**
   * Builds the select that adds an item to the list.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The select's title.
   * @param array $options
   *   The addable plugins, keyed by the id the form addresses them by. Sorted
   *   by label here so no caller has to remember to.
   * @param array $meta
   *   The op's own properties, merged in as-is.
   * @param array $ajax
   *   The '#ajax' definition rebuilding this form.
   *
   * @return array
   *   A select element.
   */
  protected function stagedAddSelect($title, array $options, array $meta, array $ajax): array {
    asort($options);
    return [
      '#type' => 'select',
      '#title' => $title,
      '#op' => StagedPluginListInterface::OP_ADD,
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => $ajax,
    ] + $meta;
  }

  /**
   * Builds the edit pane's Update and Cancel buttons.
   *
   * Cancel limits validation: it must not commit the pane it is closing. That
   * is the rule ::isLimitedSubmission() exists to make every form honour, so
   * it is applied here rather than left to each caller to remember. Update
   * does not, because it is the commit.
   *
   * @param string $name
   *   A prefix making the two buttons' names unique within the form.
   * @param bool $isNew
   *   Whether the item was added by this interaction. It labels the commit
   *   button Create rather than Update; what Cancel then does about the item
   *   is $cancelOp's job, since only the form knows how to discard one.
   * @param array $meta
   *   The op's own properties, merged into both buttons.
   * @param array $ajax
   *   The '#ajax' definition rebuilding this form.
   * @param string|null $cancelOp
   *   The op Cancel transitions to. Defaults to OP_CANCEL; a form whose
   *   "discard the item I just added" is a different transition passes its
   *   own, as the slot form does with OP_REMOVE.
   *
   * @return array
   *   An actions element.
   */
  protected function stagedEditActions(string $name, bool $isNew, array $meta, array $ajax, ?string $cancelOp = NULL): array {
    return [
      '#type' => 'actions',
      'update' => $this->stagedOpButton(
        StagedPluginListInterface::OP_UPDATE,
        'update-' . $name,
        $isNew ? $this->t('Create') : $this->t('Update'),
        $meta,
        $ajax,
        FALSE,
      ),
      'cancel' => $this->stagedOpButton(
        $cancelOp ?? StagedPluginListInterface::OP_CANCEL,
        'cancel-' . $name,
        $this->t('Cancel'),
        $meta,
        $ajax,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitRebuild(array $form, FormStateInterface $form_state): void {
    $form_state->setRebuild(TRUE);
  }

}
