<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Component form.
 *
 * @internal
 */
class InstanceComponentPublishForm extends EntityConfirmFormBase {

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

    if ($this->entity->hasField('moderation_state')) {
      /** @var \Drupal\Core\Entity\Entity\EntityFormDisplay $formDisplay */
      $formDisplay = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->load($this->entity->getEntityTypeId() . '.' . $this->entity->bundle() . '.default');
      $component = $formDisplay->getComponent('moderation_state');
      if ($component) {
        // Get the widget plugin manager.
        $widget_manager = \Drupal::service('plugin.manager.field.widget');
        // Create the widget instance.
        $widget = $widget_manager->getInstance([
          'field_definition' => $this->entity->getFieldDefinition('moderation_state'),
          'form_mode' => 'default',
          'configuration' => $component,
        ]);
        $form['#parents'] = [];
        // Build the widget form.
        $items = $this->entity->get('moderation_state');
        $form['moderation_state'] = $widget->form($items, $form, $form_state);
        $form['moderation_state']['#access'] = $items->access('edit');
        $form['moderation_state']['#weight'] = 100;
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Save the components on %label: %field_label?', [
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
    return $this->t('Save');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Are you sure you want to save this layout and all of its components?');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fieldItem = $this->fieldItem->enforceAsDraft(FALSE);
    $form_state->setRedirectUrl($this->entity->toUrl());
    $moderationState = $form_state->getValue(['moderation_state', 0, 'value']);
    if ($moderationState) {
      if ($this->entity->get('moderation_state')->value === $moderationState) {
        // Skip new revision creation if the moderation state is not changing.
        if ($this->entity instanceof SynchronizableInterface) {
          $this->entity->setSyncing(TRUE);
        }
      }
      $this->entity->set('moderation_state', $moderationState);
      if ($this->entity->getEntityType()->hasLinkTemplate('latest-version') && !$this->entity->isDefaultRevision()) {
        $form_state->setRedirectUrl($this->entity->toUrl('latest-version'));
      }
    }
    $this->entity->set('changed', \Drupal::time()->getRequestTime());
    $result = $fieldItem->saveComponents();
    $this->messenger()->addStatus($this->t('Components have been published successfully on %label: %field_label.', [
      '%label' => $this->entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]));
    return $result;
  }

}
