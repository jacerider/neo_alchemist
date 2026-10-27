<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Controller\InstanceComponentPanelController;
use Drupal\neo_alchemist\EditorState\DraftConflictException;
use Drupal\neo_alchemist\Element\InstanceComponentManage;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Panel\AlchemistPanelManager;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist_test\Plugin\AlchemistPanel\TestLabelPanel;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The editor side panel API: a panel's key in the shared draft.
 *
 * A panel drafts beside the layout, so what it writes must live and die with
 * the layout draft: kept by a tree write, discarded by Revert and Reset, and
 * handed to the panel's publish() — and persisted — on Publish. The fixture
 * panel (TestLabelPanel) drafts the entity label, so "persisted" is a label
 * read back from storage.
 *
 * @see \Drupal\neo_alchemist\Panel\AlchemistPanelInterface
 * @see \Drupal\neo_alchemist\EditorState\SharedDraftStore::setPanel()
 */
#[Group('neo_alchemist')]
class AlchemistPanelTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * The fixture panel's id.
   */
  private const PANEL = 'na_test_label';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The alchemist link templates and form classes are added by
    // neo_alchemist_entity_type_alter once a tree field exists; clear so the
    // alter re-runs with the fixture field present, then build its routes.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity']);
    \Drupal::state()->set(TestLabelPanel::STATE_KEY, TRUE);
  }

  /**
   * A tree write keeps what a panel drafted beside it.
   */
  public function testTreeWritePreservesPanelData(): void {
    $item = $this->item($this->labelledEntity());
    $this->store()->setPanel($item, self::PANEL, ['label' => 'Drafted']);

    $this->store()->set($item, $this->defaultLayout());

    $this->assertSame(['label' => 'Drafted'], $this->store()->getPanel($item, self::PANEL), 'The tree write kept the panel data.');
    $this->assertSame(2, $this->store()->version($item), 'Both writes moved the one draft version on.');
  }

  /**
   * A panel write with no draft starts one, without snapshotting the layout.
   */
  public function testPanelWriteWithoutDraftStartsOne(): void {
    $item = $this->item($this->labelledEntity());
    $this->assertFalse($item->hasDraft(), 'Premise: no draft yet.');
    $this->assertFalse($item->access('revert'), 'Premise: nothing to revert.');

    $this->store()->setPanel($item, self::PANEL, ['label' => 'Drafted']);

    $this->assertTrue($item->hasDraft(), 'Panel data alone is a draft.');
    $this->assertTrue($item->access('publish'), 'Save appears.');
    $this->assertTrue($item->access('revert'), 'Revert appears.');
    $this->assertNull($this->store()->get($item), 'The layout has no draft: nothing was snapshotted.');
    $this->assertSame(1, $this->store()->version($item));
  }

  /**
   * Publish hands the panel its data before the save, and the change persists.
   */
  public function testPublishAppliesPanelDataToTheSavedEntity(): void {
    $entity = $this->labelledEntity();
    $item = $this->item($entity);
    $this->store()->setPanel($item, self::PANEL, ['label' => 'Published label']);

    $this->publish($item, $this->store()->version($item));

    $reloaded = $this->reloadEntity($entity);
    $this->assertSame('Published label', $reloaded->label(), 'The panel data reached the saved entity.');
    $this->assertFalse($this->item($reloaded)->hasDraft(), 'Publish consumed the draft.');
    $this->assertNull($this->store()->getPanel($item, self::PANEL), 'Publish cleared the panel data.');
    $this->assertNull($this->rawStoredValue($reloaded), 'A panel-only publish left the inherited layout uncustomized.');
  }

  /**
   * A refused (stale) publish persists nothing, panel data included.
   */
  public function testStalePublishPersistsNothing(): void {
    $entity = $this->labelledEntity();
    $label = $entity->label();
    $item = $this->item($entity);
    $this->store()->setPanel($item, self::PANEL, ['label' => 'Too early']);
    $loaded = $this->store()->version($item);
    // A colleague writes the draft after the publisher loaded it.
    $this->store()->setPanel($item, self::PANEL, ['label' => 'Colleague']);

    try {
      $this->publish($item, $loaded);
      $this->fail('A publish behind the stored version should be refused.');
    }
    catch (DraftConflictException) {
    }

    $this->assertSame($label, $this->reloadEntity($entity)->label(), 'The refused publish saved no panel data.');
    $this->assertSame(['label' => 'Colleague'], $this->store()->getPanel($item, self::PANEL), 'The draft is intact.');
  }

  /**
   * A stale panel write is refused and leaves the colleague's data in place.
   */
  public function testStalePanelWriteIsRefused(): void {
    $item = $this->item($this->labelledEntity());
    $this->store()->setPanel($item, self::PANEL, ['label' => 'First'], 0);

    $this->expectException(DraftConflictException::class);
    try {
      $this->store()->setPanel($item, self::PANEL, ['label' => 'Stale'], 0);
    }
    finally {
      $this->assertSame(['label' => 'First'], $this->store()->getPanel($item, self::PANEL));
    }
  }

  /**
   * The endpoint answers a stale write with a 409 and the draft untouched.
   */
  public function testEndpointRefusesStaleWriteWithConflict(): void {
    $item = $this->item($this->labelledEntity());
    $controller = InstanceComponentPanelController::create($this->container);

    $read = $this->json($controller(Request::create('/', 'GET'), $item, self::PANEL));
    $this->assertSame(['data' => NULL, 'version' => 0, 'hasDraft' => FALSE], $read);

    $written = $controller($this->post(['data' => ['label' => 'A'], 'version' => 0]), $item, self::PANEL);
    $this->assertSame(200, $written->getStatusCode());
    $body = $this->json($written);
    $this->assertSame(1, $body['version']);
    $this->assertTrue($body['hasDraft']);
    $this->assertIsString($body['operations'], 'The re-rendered toolbar rides along.');

    $stale = $controller($this->post(['data' => ['label' => 'B'], 'version' => 0]), $item, self::PANEL);
    $this->assertSame(409, $stale->getStatusCode());
    $this->assertNotEmpty($this->json($stale)['message']);
    $this->assertSame(['label' => 'A'], $this->store()->getPanel($item, self::PANEL));
  }

  /**
   * Revert's delete and Reset both discard the panel data, unpublished.
   */
  public function testRevertAndResetDiscardPanelData(): void {
    $entity = $this->labelledEntity();
    $label = $entity->label();
    $item = $this->item($entity);

    $this->store()->setPanel($item, self::PANEL, ['label' => 'Reverted']);
    $this->store()->delete($item);
    $this->assertNull($this->store()->getPanel($item, self::PANEL), 'Revert cleared the panel data.');
    $this->assertFalse($item->hasDraft());

    $this->store()->setPanel($item, self::PANEL, ['label' => 'Reset']);
    $formObject = $this->container->get('entity_type.manager')->getFormObject('entity_test', 'alchemist_reset');
    $formObject->setEntity($entity);
    $formState = (new FormState())->set('fieldItem', $item);
    $this->container->get('form_builder')->submitForm($formObject, $formState);
    $this->assertSame([], $formState->getErrors());

    $this->assertNull($this->store()->getPanel($item, self::PANEL), 'Reset cleared the panel data.');
    $this->assertSame($label, $this->reloadEntity($entity)->label(), 'Reset did not publish the panel data.');
  }

  /**
   * A panel the account may not use is not listed, and its data not applied.
   */
  public function testDeniedPanelIsNotListedNorPublished(): void {
    $entity = $this->labelledEntity();
    $label = $entity->label();
    $item = $this->item($entity);
    $this->assertArrayHasKey(self::PANEL, $this->manager()->accessiblePanels($item, $this->container->get('current_user')));
    $allowed = $this->sidePanels($item);
    $this->assertArrayHasKey('sidepanel_' . self::PANEL, $allowed['toggles'], 'Premise: allowed, the toggle is built.');
    $this->assertStringEndsWith('/panel/' . self::PANEL, $allowed['settings'][self::PANEL]['url']);

    $this->store()->setPanel($item, self::PANEL, ['label' => 'Denied']);
    \Drupal::state()->set(TestLabelPanel::STATE_KEY, FALSE);

    $this->assertSame([], $this->manager()->accessiblePanels($item, $this->container->get('current_user')));
    $sidePanels = $this->sidePanels($item);
    $this->assertSame([[], [], []], [$sidePanels['toggles'], $sidePanels['panels'], $sidePanels['settings']], 'Nothing reaches the editor.');
    $this->assertFalse($this->manager()->access($item, self::PANEL, $this->container->get('current_user'))->isAllowed());

    $this->publish($item, $this->store()->version($item));
    $this->assertSame($label, $this->reloadEntity($entity)->label(), 'Denied panel data was not applied.');
  }

  /**
   * Panels are entity-scope only: an unknown id and an anonymous user get none.
   */
  public function testAccessGates(): void {
    $item = $this->item($this->labelledEntity());
    $this->assertFalse($this->manager()->access($item, 'no_such_panel', $this->container->get('current_user'))->isAllowed());
    $this->assertSame([], $this->manager()->accessiblePanels($item, new UserSession(['uid' => 0])), 'No update access, no panel.');
  }

  /**
   * A panel's pill sits in the band's centre; its badge leads the trail.
   */
  public function testBadgeLeadsTheTrail(): void {
    $item = $this->item($this->labelledEntity());
    $children = static fn (array $build): array => array_values(array_filter(array_keys($build), static fn ($key) => $key[0] !== '#'));
    $plain = $this->manageElement($item);
    $this->assertArrayNotHasKey('badges', $plain['#top_end'], 'No badge: the trail is unchanged.');
    $this->assertSame(['scale', 'size', 'history'], $children($plain['#top_end']), 'The trail holds the scale and device controls, then Undo and Redo.');
    $this->assertArrayHasKey('view', $plain['#top_end']['size'], 'View options is part of the device group.');
    $this->assertSame('sidepanel_' . self::PANEL, $children($plain['#top_center'])[2] ?? NULL, 'The panel has its pill in the centre, after Add and Layers.');

    \Drupal::state()->set(TestLabelPanel::BADGE_STATE_KEY, TRUE);
    $this->assertArrayHasKey('sidepanel_' . self::PANEL, $this->sidePanels($item)['toggles'], 'A panel with a badge keeps its pill.');
    $badged = $this->manageElement($item);
    $this->assertSame(['badges', 'scale', 'size', 'history'], $children($badged['#top_end']), 'Badges lead the trail, in the same container as what follows.');
    $badge = $badged['#top_end']['badges']['badge_' . self::PANEL];
    $this->assertSame(self::PANEL, $badge['#attributes']['data-alchemist-badge']);
    $this->assertSame(['content'], $children($badge), "The badge is apart from the panel's pill.");
    $this->assertSame('button', $badge['content']['#tag']);
    $this->assertContains('neo_alchemist/panels', $badged['#attached']['library'], 'The badge client half loads with the editor.');
  }

  /**
   * The band: Back leads, the centre builds, the save group follows the draft.
   *
   * Back leads alone, before the title; + Add, Layers and the panel pills sit
   * in the centre. The status line says where the draft stands either way.
   * The save group has a slot of its own, the one a write re-renders: with no
   * draft it is quiet (no Save, no Discard); with one it offers Save, the one
   * filled button, then ⋯, which holds Discard unsaved changes (Revert).
   */
  public function testSaveGroupTrailsAndFollowsTheDraft(): void {
    $item = $this->item($this->labelledEntity());
    $children = static fn (array $build): array => array_values(array_filter(array_keys($build), static fn ($key) => $key[0] !== '#'));

    $quiet = $this->manageElement($item);
    $this->assertSame(['back'], $children($quiet['#top_start']), 'Back leads the band alone.');
    $this->assertSame(['add', 'layers'], array_slice($children($quiet['#top_center']), 0, 2), 'Add, then Layers, lead the centre.');
    $this->assertArrayNotHasKey('#bottom_start', $quiet, 'There is no footer bar.');
    $this->assertSame('All changes saved', (string) $quiet['#top_status']['status']['label']['#plain_text']);
    $this->assertNotContains('publish', $children($quiet['#top_save']), 'No draft: no Save.');
    $this->assertArrayNotHasKey('revert', $quiet['#top_save']['more'] ?? [], 'No draft: nothing to discard.');

    $this->store()->set($item, $this->defaultLayout());
    $element = $this->manageElement($item);
    $this->assertSame('Unsaved changes', (string) $element['#top_status']['status']['label']['#plain_text']);
    $save = $element['#top_save'];
    $this->assertSame(['publish', 'more'], $children($save), 'A draft: Save, then ⋯.');
    $this->assertContains('btn-primary', $save['publish']['#attributes']['class'], 'Save is the filled button.');
    $this->assertSame('revert', $children($save['more']['menu'])[0], 'Discard unsaved changes leads the ⋯ menu.');
  }

  /**
   * A panel the editor may not open has no badge either.
   */
  public function testDeniedPanelHasNoBadge(): void {
    $item = $this->item($this->labelledEntity());
    \Drupal::state()->set(TestLabelPanel::BADGE_STATE_KEY, TRUE);
    $this->assertArrayHasKey('badge_' . self::PANEL, $this->sidePanels($item)['badges'], 'Premise: allowed, the badge is built.');

    \Drupal::state()->set(TestLabelPanel::STATE_KEY, FALSE);
    $this->assertSame([], $this->sidePanels($item)['badges']);
    $this->assertArrayNotHasKey('badges', $this->manageElement($item)['#top_end']);
  }

  /**
   * The editor element as its pre-render builds it.
   */
  private function manageElement(ComponentTreeItem $item): array {
    return $this->container->get('renderer')->executeInRenderContext(new RenderContext(), static fn () => InstanceComponentManage::preRenderManage(['#neo_field' => $item]));
  }

  /**
   * A saved test entity with a label the fixture panel can change.
   */
  private function labelledEntity(): EntityTest {
    $entity = $this->createTestEntity();
    $entity->set('name', 'Original label')->save();
    return $this->reloadEntity($entity);
  }

  /**
   * Publishes the way the publish form does.
   */
  private function publish(ComponentTreeItem $item, int $loadedVersion): void {
    $item->enforceAsDraft(FALSE)->carryDraftVersion($loadedVersion)->saveComponents();
  }

  /**
   * The entity's tree item, in draft mode as the editor routes load it.
   */
  private function item(EntityTest $entity): ComponentTreeItem {
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item->enforceAsDraft();
  }

  /**
   * The editor's side panel build, inside the render context it runs in.
   */
  private function sidePanels(ComponentTreeItem $item): array {
    return $this->container->get('renderer')->executeInRenderContext(new RenderContext(), static fn () => ComponentManageHelper::buildSidePanels($item));
  }

  /**
   * A JSON POST to the panel endpoint.
   */
  private function post(array $payload): Request {
    return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
  }

  /**
   * A JSON response's decoded body.
   */
  private function json(Response $response): array {
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * The shared draft store.
   */
  private function store(): SharedDraftStore {
    return $this->container->get('neo_alchemist.shared_draft_store');
  }

  /**
   * The panel manager.
   */
  private function manager(): AlchemistPanelManager {
    return $this->container->get('plugin.manager.neo_alchemist_panel');
  }

}
