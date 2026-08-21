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
class InstanceComponentRevertForm extends EntityConfirmFormBase {

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
  public function getQuestion() {
    return $this->t('Revert the components on %label: %field_label?', [
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
    return $this->t('Revert');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('<p>This layout has unsaved changes. Reverting will throw those changes away and bring back the last saved version. That is the version currently shown on the site.</p><p class="text-xs">Everything you have done since the last save will be lost: components you added or removed, components you moved around, and any content or settings you edited inside them.</p><p class="text-xs">This cannot be undone. If you would rather keep your work, cancel and save the layout instead.</p>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fieldItem = $this->fieldItem;
    $this->sharedDraftStore->delete($fieldItem);
    $form_state->setRedirectUrl($this->entity->toUrl());
    $this->messenger()->addStatus($this->t('Components have been reverted successfully on %label: %field_label.', [
      '%label' => $this->entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]));
    return SAVED_UPDATED;
  }

}
