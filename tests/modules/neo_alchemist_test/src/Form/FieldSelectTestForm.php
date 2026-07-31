<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Hosts a single neo_field_select so its rendering can be asserted.
 *
 * The element has to go through a real form build: the two regressions it
 * guards against — a missing `id` attribute and an unset `#ajax['event']` —
 * only appear once FormBuilder and the render pipeline have run, and both are
 * silent. Calling the process callbacks directly would miss them.
 */
final class FieldSelectTestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_alchemist_test_field_select';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state. Element overrides are read from its 'element' storage.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Overrides on the LEFT: `+` keeps the left operand's keys, so putting the
    // defaults there would silently ignore every override a test passed.
    $form['field'] = ($form_state->get('element') ?? []) + [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Field'),
      '#component' => 'na_heading',
      '#prop' => 'heading',
      '#shape' => 'heading~title',
      '#ajax' => [
        'callback' => '::noop',
        'wrapper' => 'test-wrapper',
      ],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];
    return $form;
  }

  /**
   * Ajax placeholder.
   *
   * @param array $form
   *   The form.
   *
   * @return array
   *   The form.
   */
  public function noop(array $form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
