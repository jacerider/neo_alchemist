<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
  use StayOnCanvasTrait;

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
    $form['draft_version'] = $this->draftVersionField($this->fieldItem->draftVersion());
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
    // Whether the save will leave the canvas: canonical stands in for both
    // targets, as latest-version is only chosen once the save has run.
    $after = $this->savedUrl(NULL)
      ? $this->t('Saving takes you to the page, and you can come back to edit again at any time.')
      : $this->t('You can keep editing afterwards and save again at any time.');
    $note = $this->entity->hasField('moderation_state')
      ? $this->t('Use the workflow state below to control whether these changes go live now or wait for review. @after', ['@after' => $after])
      : $after;
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
    $state = $this->savedUnpublishedState($moderationState);
    $message = $state
      ? $this->t('Saved %label as @state.', [
        '%label' => $this->entity->label(),
        '@state' => $state,
      ])
      : $this->t('Saved %label.', ['%label' => $this->entity->label()]);
    if ($url = $this->savedUrl($moderationState)) {
      $this->leaveCanvas($form_state, $url, $message);
    }
    else {
      // Nowhere to show it: stay on the canvas, as Revert does.
      $form_state->setRedirectUrl($this->getCancelUrl());
      $this->reportOutcome($form_state, $state
        ? $this->t('Saved as @state', ['@state' => $state])
        : $this->t('Saved'), $message);
    }
    return $result;
  }

  /**
   * Where a save sends the editor: the page that shows what was saved.
   *
   * Saved into a forward revision (a published node saved as Draft), that is
   * the latest-version page, since the canonical one still shows the live
   * revision. Otherwise it is the canonical page. NULL when the entity has
   * neither, or the editor cannot view it, and the save stays on the canvas.
   *
   * @param string|null $moderationState
   *   The moderation state submitted with the save, if the entity has one.
   *
   * @return \Drupal\Core\Url|null
   *   The page, or NULL.
   */
  protected function savedUrl(?string $moderationState): ?Url {
    $entityType = $this->entity->getEntityType();
    // saveComponents() saved $this->entity, so Content Moderation's presave
    // has flipped isDefaultRevision() to match the revision it wrote.
    $rel = $moderationState
      && $entityType->hasLinkTemplate('latest-version')
      && !$this->entity->isDefaultRevision() ? 'latest-version' : 'canonical';
    if (!$entityType->hasLinkTemplate($rel)) {
      return NULL;
    }
    $url = $this->entity->toUrl($rel);
    return $url->access() ? $url : NULL;
  }

  /**
   * The label of the state a save went into, when that state is not live.
   *
   * Saved into a state that does not publish (a Draft, a Review), the change
   * is not live, and "Saved" alone would let the editor think it was.
   *
   * @param string|null $moderationState
   *   The moderation state submitted with the save, if the entity has one.
   *
   * @return string|null
   *   The state's label, or NULL when the save went live or there is no
   *   workflow.
   */
  protected function savedUnpublishedState(?string $moderationState): ?string {
    if (!$moderationState || !\Drupal::hasService('content_moderation.moderation_information')) {
      return NULL;
    }
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($this->entity);
    $plugin = $workflow?->getTypePlugin();
    $state = $plugin && $plugin->hasState($moderationState) ? $plugin->getState($moderationState) : NULL;
    if ($state && method_exists($state, 'isPublishedState') && !$state->isPublishedState()) {
      return (string) $state->label();
    }
    return NULL;
  }

}
