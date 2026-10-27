<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\EditorState\EditorHistory;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Form\DraftConflictMessageTrait;
use Drupal\neo_alchemist\Panel\AlchemistPanelManager;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * An editor side panel's JSON endpoint into its key of the shared draft.
 *
 * GET answers {data, version, hasDraft}. POST takes {data, version} and writes
 * the panel's key under the draft's version guard, answering {version,
 * hasDraft, operations} — operations being the re-rendered header save group,
 * so the first write makes Save and Revert appear — or a 409 {message,
 * version} when a colleague moved the draft on.
 *
 * @see \Drupal\neo_alchemist\Panel\AlchemistPanelInterface
 */
final class InstanceComponentPanelController extends ControllerBase {

  use DraftConflictMessageTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly SharedDraftStore $sharedDraftStore,
    private readonly AlchemistPanelManager $panelManager,
    private readonly RendererInterface $renderer,
    private readonly EditorHistory $editorHistory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.shared_draft_store'),
      $container->get('plugin.manager.neo_alchemist_panel'),
      $container->get('renderer'),
      $container->get('neo_alchemist.editor_history'),
    );
  }

  /**
   * Route access: the panel exists here and its own access() allows it.
   */
  public function access(ComponentTreeItem $neo_field, string $panel, AccountInterface $account): AccessResultInterface {
    return $this->panelManager->access($neo_field, $panel, $account);
  }

  /**
   * Reads (GET) or writes (POST) the panel's drafted data.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field, string $panel): JsonResponse {
    if ($request->isMethod('POST')) {
      return $this->write($request, $neo_field, $panel);
    }
    return new JsonResponse([
      'data' => $this->sharedDraftStore->getPanel($neo_field, $panel),
      'version' => $this->sharedDraftStore->version($neo_field),
      'hasDraft' => $neo_field->hasDraft(),
    ]);
  }

  /**
   * Writes the posted data to the panel's key.
   */
  private function write(Request $request, ComponentTreeItem $neo_field, string $panel): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    $version = is_array($payload) ? ($payload['version'] ?? NULL) : NULL;
    if (!is_array($payload) || !array_key_exists('data', $payload) || ($version !== NULL && !is_int($version))) {
      return new JsonResponse(['message' => (string) $this->t('Expected a JSON body of {data, version}.')], 400);
    }
    $versionBefore = $this->sharedDraftStore->version($neo_field);
    try {
      $this->sharedDraftStore->setPanel($neo_field, $panel, $payload['data'], $version);
    }
    catch (DraftConflictException $conflict) {
      // The trait reads the user storage off the property ControllerBase only
      // fills on first use.
      $this->entityTypeManager();
      return new JsonResponse([
        'message' => (string) $this->draftConflictMessage($conflict, $neo_field),
        'version' => $conflict->getStoredVersion(),
      ], 409);
    }
    // A panel write is no undo step, but it is this editor's own: the history
    // follows the version on rather than reading it as a colleague's.
    $this->editorHistory->noteWrite($neo_field, $versionBefore);
    $operations = ComponentManageHelper::buildDynamicOperations($neo_field);
    return new JsonResponse([
      'version' => $this->sharedDraftStore->version($neo_field),
      'hasDraft' => $neo_field->hasDraft(),
      'operations' => (string) $this->renderer->renderInIsolation($operations),
    ]);
  }

}
