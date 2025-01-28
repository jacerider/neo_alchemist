<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Component form.
 *
 * @internal
 */
class InstanceComponentResetForm extends EntityConfirmFormBase {

  /**
   * The entity being edited.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $entity;

  /**
   * The field item.
   *
   * @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   */
  protected $fieldItem;

  /**
   * Gets the actual form array to be built.
   *
   * @see \Drupal\Core\Entity\EntityForm::processForm()
   * @see \Drupal\Core\Entity\EntityForm::afterBuild()
   */
  public function form(array $form, FormStateInterface $form_state) {
    $this->fieldItem = $form_state->get('fieldItem');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Reset the components on %label: %field_label?', [
      '%label' => $this->entity->label(),
      '%field_label' => $this->fieldItem->getFieldDefinition()->getLabel(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->fieldItem->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Reset');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Are you sure you want to reset this layout to its default components.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fieldItem = $this->fieldItem->enforceAsDraft(FALSE)->resetComponents();
    $form_state->setRedirectUrl($this->entity->toUrl());
    $result = $fieldItem->saveComponents();
    $this->messenger()->addStatus($this->t('Components have been reset successfully on %label: %field_label.', [
      '%label' => $this->entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]));
    return $result;
  }

}
