<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * {@inheritdoc}
   */
  public function getBaseFormId() {
    $base_form_id = 'neo_compponent_' . $this->entity->getEntityTypeId() . '_form';
    if ($base_form_id == $this->getFormId()) {
      $base_form_id = NULL;
    }
    return $base_form_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    $form_id = 'neo_compponent_' . $this->entity->getEntityTypeId();
    if ($this->entity->getEntityType()->hasKey('bundle')) {
      $form_id .= '_' . $this->entity->bundle();
    }
    return $form_id . '_form';
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form_state->set('neo_component_form', TRUE);
    $this->instance = $form_state->get('neo_component_instance');

    // Add #process and #after_build callbacks.
    $form['#process'][] = '::processForm';
    $form['#after_build'][] = '::afterBuild';

    $form['values'] = [
      '#parents' => ['values'],
    ];
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      $form['values'][$propName] = $shape->getForm($form['values'], $form_state);
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->instance->isPublished(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    // No entity building is needed.
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = [
      'status' => (int) !empty($form_state->getValue('status')),
    ];
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      $shape->validateForm($form['values'][$propName], $form_state, $form_state->getValue([
        'values',
        $propName,
      ], []));
      $value = $shape->massageFormValues($form, $form_state, $form_state->getValue([
        'values',
        $propName,
      ], []));
      $values['props'][$propName]['field_type'] = $shape->getFieldType();
      $values['props'][$propName]['value'] = $value;
    }
    $this->instance->setValues($values);
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->instance->toUrl(),
      '#attributes' => [
        'data-neo-modal-close' => '1',
      ],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $form_state->setRedirectUrl($this->instance->toUrl());
    return $this->instance->save();
  }

}
