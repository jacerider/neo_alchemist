<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * The shared draft store.
   *
   * Not promoted, and protected rather than private: form objects are
   * serialized into the form cache, and DependencySerializationTrait can only
   * swap a service for its id when it can see the property.
   *
   * @var \Drupal\neo_alchemist\EditorState\SharedDraftStore
   */
  protected $sharedDraftStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->sharedDraftStore = $container->get('neo_alchemist.shared_draft_store');
    return $form;
  }

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
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    // Destructive: the alert colour, which neo_modal carries over to the
    // footer button it draws in place of this one.
    $actions['submit']['#attributes']['class'][] = 'btn-alert';
    return $actions;
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
    return $this->t('<p>Resetting removes the layout built here and puts back the default layout.</p><p class="text-xs">Every component added here will be removed, along with the content and settings inside them. This applies to both the saved version and any unsaved changes. What remains is the default layout, exactly as it looked before anyone customized it.</p><p class="text-xs">This is saved right away and cannot be undone. To only discard unsaved changes and keep the saved layout, cancel and revert instead.</p>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fieldItem = $this->fieldItem->enforceAsDraft(FALSE)->resetComponents();
    $form_state->setRedirectUrl($this->entity->toUrl());
    // Reset throws the whole draft away, editor panels' data included. The
    // commit below would otherwise read that data and publish it — the commit
    // path is Publish's — so the draft goes first, unguarded like Revert's.
    $this->sharedDraftStore->delete($fieldItem);
    $result = $fieldItem->saveComponents();
    $this->messenger()->addStatus($this->t('Components have been reset successfully on %label: %field_label.', [
      '%label' => $this->entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]));
    return $result;
  }

}
