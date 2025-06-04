<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Provides the filter format disable form.
 *
 * @internal
 */
class InstanceComponentDeleteForm extends EntityConfirmFormBase {

  use ComponentAjaxFormHelperTrait;

  /**
   * The entity being edited.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * Component.
   *
   * @var \Drupal\neo_alchemist\ComponentInstanceInterface
   */
  protected $instance;

  /**
   * Gets the actual form array to be built.
   *
   * @see \Drupal\Core\Entity\EntityForm::processForm()
   * @see \Drupal\Core\Entity\EntityForm::afterBuild()
   */
  public function form(array $form, FormStateInterface $form_state) {
    $this->instance = $form_state->get('neo_component_instance');
    $form_state->set('neo_component_form', TRUE);
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->instance->getFieldItem()));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to remove the component %label?', ['%label' => $this->instance->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->instance->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Remove');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Removed components can be restored if you do not publish the changes.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->instance->delete();

    $fieldItem = $this->instance->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $this->messenger()->addStatus($this->t('Removed component %name successfully on %label: %field_label.', [
      '%name' => $this->instance->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $this->entity->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

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

}
