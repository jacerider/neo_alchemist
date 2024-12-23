<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\Ajax\InstanceComponentPreviewIframeHelper;

/**
 * Component form.
 */
final class InstanceComponentForm extends ContentEntityForm {

  use InstanceComponentPreviewIframeHelper;

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * Before.
   *
   * @var string|null
   */
  protected $before;

  /**
   * After.
   *
   * @var string|null
   */
  protected $after;

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
    $form['#parents'] = [];
    $form['#neo_style'] = 'clean';
    $form_state->set('neo_component_form', TRUE);
    $this->instance = $form_state->get('neo_component_instance');
    $this->before = $form_state->get('before');
    $this->after = $form_state->get('after');
    if (!$form_state->has('original_values')) {
      $form_state->set('original_values', $this->instance->getValues());
    }

    // Add #process and #after_build callbacks.
    $form['#process'][] = '::processForm';
    $form['#after_build'][] = '::afterBuild';

    $form['values'] = [
      '#type' => 'container',
    ];
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (!$shape->isEditable()) {
        continue;
      }
      $subform = [
        '#type' => 'container',
        '#parents' => ['values'],
      ];
      $subform_state = SubformState::createForSubform($subform, $form, $form_state);
      $form['values'][$propName] = $shape->getForm($subform, $subform_state);
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
    $this->instance->setRebuilding(TRUE);
    $values = [
      'status' => (int) !empty($form_state->getValue('status')),
    ];
    $original_values = $form_state->get('original_values') ?? [];
    foreach ($this->instance->getPropShapes() as $propName => $shape) {
      if (isset($form['values'][$propName])) {
        $subform_state = SubformState::createForSubform($form['values'][$propName], $form, $form_state);
        $originalValue = $original_values['props'][$propName]['value'] ?? [];
        $value = $subform_state->getValues();
        $shape->validateForm($form['values'][$propName], $subform_state, $value);
        $values['props'][$propName]['shape'] = $shape->getPluginId();
        if (is_array($value) && !empty($value)) {
          $values['props'][$propName]['value'] = $shape->massageFormValues($value, $originalValue, $form['values'][$propName], $subform_state);
        }
        else {
          $values['props'][$propName]['value'] = $originalValue;
        }
        $values['props'][$propName]['options'] = $shape->getNestedOptions();
      }
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
    if ($this->isAjax()) {
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
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
    $this->instance->setRebuilding(FALSE);
    $form_state->setRedirectUrl($this->instance->toUrl());

    $fieldItem = $this->instance->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => $this->instance->isNew() ? 'Created' : 'Updated',
      '%name' => $this->instance->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    // If we have requested a position change, we make it here.
    $position = $this->after ? 'after' : ($this->before ? 'before' : NULL);
    if ($position) {
      $this->instance->getFieldItem()->moveComponent($this->instance->uuid(), $this->after ?: $this->before, $position);
    }

    return $this->instance->save();
  }

}
