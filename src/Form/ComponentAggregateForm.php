<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Component form.
 *
 * @internal
 */
class ComponentAggregateForm extends EntityConfirmFormBase {

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * Gets the actual form array to be built.
   *
   * @see \Drupal\Core\Entity\EntityForm::processForm()
   * @see \Drupal\Core\Entity\EntityForm::afterBuild()
   */
  public function form(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->entity->isAggregate()
      ? $this->t('%label: Disable Aggregation', ['%label' => $this->entity->label()])
      : $this->t('%label: Enable Aggregation', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->entity->isAggregate()
      ? $this->t('Disable')
      : $this->t('Enable');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Aggregation combines all props into a single prop for management purposes. It is ideal for simplifying value binding in complex components.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->set('aggregate', !$this->entity->isAggregate());
    $this->entity->save();
    $this->messenger()->addStatus($this->t('The component %label has been updated.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('canonical'));
  }

}
