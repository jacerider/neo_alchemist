<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Ajax\ComponentAjaxHelperTrait;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageFocusCommand;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageToastCommand;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\EditorState\EditorHistory;
use Drupal\neo_alchemist\Form\DraftConflictMessageTrait;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Undoes or redoes the current user's last step over the shared draft.
 *
 * Answers like any other write: the preview reloads, the save group and the
 * Undo and Redo buttons re-render, and the component the step acted on is
 * selected again. A toast names what happened, or why nothing did.
 *
 * @see \Drupal\neo_alchemist\EditorState\EditorHistory
 */
final class InstanceComponentHistoryController extends ControllerBase {

  use AjaxHelperTrait;
  use ComponentAjaxHelperTrait;
  use DraftConflictMessageTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly EditorHistory $history,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.editor_history'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field, string $direction) {
    $step = NULL;
    $type = 'status';
    try {
      $step = $direction === 'redo' ? $this->history->redo($neo_field) : $this->history->undo($neo_field);
      $message = match (TRUE) {
        $step === NULL && $direction === 'redo' => $this->t('Nothing to redo'),
        $step === NULL => $this->t('Nothing to undo'),
        $direction === 'redo' => $this->t('Redid @step', ['@step' => $step['label']]),
        default => $this->t('Undid @step', ['@step' => $step['label']]),
      };
    }
    catch (DraftConflictException $conflict) {
      // The trait reads the user storage off the property ControllerBase only
      // fills on first use.
      $this->entityTypeManager();
      $message = $this->t('@editor edited this layout since your last change', [
        '@editor' => $this->draftConflictEditorName($conflict->getLastEditorUid()),
      ]);
      $type = 'error';
    }

    if (!$this->isAjax()) {
      $this->messenger()->addMessage($message, $type);
      $url = $neo_field->toUrl('manage');
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }
    $response = $this->getComponentReponse(ComponentManageHelper::getId($neo_field), NULL, $neo_field);
    if (isset($step['uuid'])) {
      $response->addCommand(new InstanceComponentManageFocusCommand($step['uuid'], TRUE));
    }
    $response->addCommand(new InstanceComponentManageToastCommand($message, $type));
    return $response;
  }

}
