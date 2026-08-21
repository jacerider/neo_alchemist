<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 *
 * @internal
 */
class InstanceComponentPublishForm extends EntityConfirmFormBase {

  use DraftConflictMessageTrait;

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
   * The field widget plugin manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected $widgetManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.field.widget'),
    );
  }

  /**
   * InstanceComponentPublishForm constructor.
   *
   * @param \Drupal\Core\Field\WidgetPluginManager $widget_manager
   *   The field widget plugin manager.
   */
  public function __construct(WidgetPluginManager $widget_manager) {
    $this->widgetManager = $widget_manager;
  }

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
        // Create the widget instance.
        $widget = $this->widgetManager->getInstance([
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

    // The shared draft's version when this confirmation opened, round-tripped
    // on submit so a stale publish — someone edited the draft while the
    // publisher sat here — is refused rather than releasing a version they
    // never saw.
    $form['draft_version'] = $this->draftVersionField($this->fieldItem);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // Refuse a publish against a draft a colleague moved past while the
    // publisher sat on the confirmation. Checked here, in the validation phase,
    // because a form error cannot be set once validation has finished.
    $fieldItem = $form_state->get('fieldItem');
    if ($fieldItem && ($conflict = $fieldItem->draftConflict((int) $form_state->getValue('draft_version')))) {
      $this->surfaceDraftConflict($conflict, $fieldItem, $form, $form_state);
    }
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
    $note = $this->entity->hasField('moderation_state')
      ? $this->t('Use the workflow state below to control whether these changes go live now or wait for review. You can keep editing afterwards and save again at any time.')
      : $this->t('You can keep editing afterwards and save again at any time.');
    $description = $this->t('<p>Saving takes the changes you have been working on and makes them the saved version of this layout.</p><p class="text-xs">Everything you have done since the last save is included: components you added or removed, components you moved around, and any content or settings you edited inside them.</p><p class="text-xs">@note</p>', [
      '@note' => $note,
    ]);
    // The draft is shared: name the other contributors before releasing their
    // work, so the publisher does not release a colleague's unfinished
    // component unaware. The publisher is excluded — they know what they are
    // publishing — and the whole draft still publishes as one thing, with no
    // per-contributor granularity. Nothing is added when no one else has
    // written to the draft.
    $contributors = ComponentManageHelper::draftContributorNames($this->fieldItem, (int) $this->currentUser()->id());
    if ($contributors) {
      $description = new FormattableMarkup('@description@attribution', [
        '@description' => $description,
        '@attribution' => $this->t('<p class="text-xs">This draft also includes work from @contributors. Saving releases all of it together.</p>', [
          '@contributors' => implode(', ', $contributors),
        ]),
      ]);
    }
    return $description;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fieldItem = $this->fieldItem->enforceAsDraft(FALSE);
    // Carry the loaded version into the publish commit so the store refuses a
    // stale release even in the gap after validateForm() cleared it. The
    // commit deletes the draft before persisting, and the delete throws first,
    // so a refused publish persists nothing.
    $fieldItem->carryDraftVersion((int) $form_state->getValue('draft_version'));
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
    }
    try {
      $result = $fieldItem->saveComponents();
    }
    catch (DraftConflictException $conflict) {
      $this->messenger()->addError($this->draftConflictMessage($conflict, $fieldItem));
      $form_state->setRebuild();
      return SAVED_UPDATED;
    }
    // saveComponents() saves $this->entity, so Content Moderation's presave has
    // now flipped isDefaultRevision() to reflect the saved revision. If the
    // save created a non-default (forward) revision, e.g. published -> draft,
    // redirect to the latest-version route.
    if ($moderationState && $this->entity->getEntityType()->hasLinkTemplate('latest-version') && !$this->entity->isDefaultRevision()) {
      $form_state->setRedirectUrl($this->entity->toUrl('latest-version'));
    }
    $this->messenger()->addStatus($this->t('Components have been published successfully on %label: %field_label.', [
      '%label' => $this->entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]));
    return $result;
  }

}
