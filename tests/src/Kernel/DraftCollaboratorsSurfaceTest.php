<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\UserSession;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Form\InstanceComponentPublishForm;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * The draft's collaborators, surfaced onto the editor and publish confirmation.
 *
 * Presence and publish attribution are both thin reads of the one draft record
 * the shared draft store carries — last editor / last-modified for "who else
 * has been in the draft and how recently", and the contributor set for "whose
 * work you are about to publish". The rendering has no browser-test
 * infrastructure, so these tests assert the data-level contract the two
 * surfaces read: the item read-throughs report the store's metadata, presence
 * names a colleague but never the current user, and publish attribution names
 * the other contributors without the publisher.
 *
 * @see \Drupal\neo_alchemist\ComponentManageHelper::buildDraftPresence()
 * @see \Drupal\neo_alchemist\ComponentManageHelper::draftContributorNames()
 */
#[Group('neo_alchemist')]
class DraftCollaboratorsSurfaceTest extends HybridFieldKernelTestBase {

  /**
   * The item read-throughs report the store metadata the surfaces read.
   *
   * The layout editor and publish confirmation reach the draft's last editor,
   * last-modified time and contributor set through the field item, not the
   * store directly — so the read-throughs must report exactly what the store
   * holds.
   */
  public function testItemReadThroughsReportStoreMetadata(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    // A draftless item reports nothing.
    $this->assertNull($item->draftLastEditor(), 'A draftless item has no last editor.');
    $this->assertNull($item->draftLastModified(), 'A draftless item has no last-modified time.');
    $this->assertSame([], $item->draftContributors(), 'A draftless item has no contributors.');

    // Jane writes, then Bob writes: the read-throughs follow the store.
    $jane = $this->createEditor('Jane');
    $bob = $this->createEditor('Bob');
    $currentUser->setAccount($jane);
    $store->set($item, ['tree' => '{}', 'props' => '[]']);
    $currentUser->setAccount($bob);
    $store->set($item, ['tree' => '{"b":2}', 'props' => '[]']);

    $this->assertSame((int) $bob->id(), $item->draftLastEditor(), 'The last editor follows the most recent writer.');
    $this->assertSame([(int) $jane->id(), (int) $bob->id()], $item->draftContributors(), 'Both writers accumulate as contributors.');
    $this->assertNotNull($item->draftLastModified(), 'The write records a last-modified time.');
  }

  /**
   * Presence names another editor and how recently, but never the viewer.
   *
   * The whole point is to know when a colleague is in the draft, so presence
   * shows the last editor only when it is someone else — surfacing the viewer's
   * own edit would report on nobody but the viewer.
   */
  public function testPresenceNamesAnotherEditorButNotYourself(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    $jane = $this->createEditor('Jane');
    $bob = $this->createEditor('Bob');

    // No draft yet: nothing to surface.
    $this->assertSame([], ComponentManageHelper::buildDraftPresence($item), 'A draftless editor shows no presence.');

    // Jane edits the draft.
    $currentUser->setAccount($jane);
    $store->set($item, ['tree' => '{}', 'props' => '[]']);

    // Jane, still the last editor, is shown nothing about that edit.
    $this->assertSame([], ComponentManageHelper::buildDraftPresence($item), 'The last editor is not shown their own presence.');

    // Bob opens the editor and sees that Jane has been in the draft.
    $currentUser->setAccount($bob);
    $presence = ComponentManageHelper::buildDraftPresence($item);
    $this->assertNotSame([], $presence, 'A colleague sees the other editor as present.');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($presence);
    $this->assertStringContainsString('Jane', $rendered, 'Presence names the other editor.');
    $this->assertStringContainsString('ago', $rendered, 'Presence says how recently.');
  }

  /**
   * Publish attribution names the other contributors, without the publisher.
   *
   * Before releasing the whole draft, the confirmation states whose work is
   * included so the publisher does not release a colleague's unfinished
   * component unaware — excluding the publisher, who knows what they publish.
   */
  public function testPublishAttributionNamesOtherContributors(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    $jane = $this->createEditor('Jane');
    $bob = $this->createEditor('Bob');
    $carol = $this->createEditor('Carol');

    $currentUser->setAccount($jane);
    $store->set($item, ['tree' => '{}', 'props' => '[]']);
    $currentUser->setAccount($bob);
    $store->set($item, ['tree' => '{"b":2}', 'props' => '[]']);
    $currentUser->setAccount($carol);
    $store->set($item, ['tree' => '{"c":3}', 'props' => '[]']);

    // Carol publishes: the publisher is named nowhere, both colleagues are.
    $names = ComponentManageHelper::draftContributorNames($item, (int) $carol->id());
    $this->assertSame(['Jane', 'Bob'], $names, 'Attribution names the other contributors in first-write order.');

    // With no one excluded, the whole contributor set is named.
    $this->assertSame(['Jane', 'Bob', 'Carol'], ComponentManageHelper::draftContributorNames($item), 'Excluding no one names every contributor.');

    // Clearing the record — what publish/discard/revert do — clears the set.
    $store->delete($item);
    $this->assertSame([], ComponentManageHelper::draftContributorNames($item, (int) $carol->id()), 'A cleared draft names no contributors.');
  }

  /**
   * The publish confirmation renders the attribution, excluding the publisher.
   *
   * The data seam is covered by draftContributorNames(); this exercises the
   * publish form's own description rendering — the surface the acceptance says
   * is verified by exercise — so the names actually reach the confirmation and
   * the publisher is not among them.
   */
  public function testPublishConfirmationRendersContributorAttribution(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    $jane = $this->createEditor('Jane');
    $carol = $this->createEditor('Carol');
    $currentUser->setAccount($jane);
    $store->set($item, ['tree' => '{}', 'props' => '[]']);
    $currentUser->setAccount($carol);
    $store->set($item, ['tree' => '{"c":3}', 'props' => '[]']);

    // Carol is the publisher: the confirmation names Jane, not the publisher.
    $form = new class($this->container->get('plugin.manager.field.widget')) extends InstanceComponentPublishForm {

      /**
       * Renders the confirmation description with the draft context set.
       */
      public function renderDescription($entity, ComponentTreeItem $item): string {
        $this->entity = $entity;
        $this->fieldItem = $item;
        return (string) $this->getDescription();
      }

    };
    $description = $form->renderDescription($item->getEntity(), $item);
    $this->assertStringContainsString('Jane', $description, 'The confirmation names the other contributor.');
    $this->assertStringNotContainsString('Carol', $description, 'The publisher is not named among the contributors.');
  }

  /**
   * An editor whose account no longer resolves degrades to a neutral label.
   */
  public function testUnresolvableEditorDegradesToNeutralLabel(): void {
    $item = $this->fieldItem();
    $store = $this->sharedDraftStore();
    $currentUser = $this->container->get('current_user');

    // A uid that wrote the draft but does not resolve to a loadable account.
    $ghostUid = 987654;
    $currentUser->setAccount(new UserSession(['uid' => $ghostUid]));
    $store->set($item, ['tree' => '{}', 'props' => '[]']);

    $viewer = $this->createEditor('Viewer');
    $currentUser->setAccount($viewer);
    $presence = ComponentManageHelper::buildDraftPresence($item);
    $this->assertNotSame([], $presence, 'A colleague is still surfaced even when their account is gone.');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($presence);
    $this->assertStringContainsString('editor', strtolower($rendered), 'An unresolvable editor degrades to a neutral label.');
    $this->assertSame([], ComponentManageHelper::draftContributorNames($item, $ghostUid), 'A ghost contributor excluded as the publisher names no one.');
    $this->assertNotSame([], ComponentManageHelper::draftContributorNames($item), 'An unresolvable contributor is still named, neutrally.');
  }

  /**
   * The container-wired store records metadata (current user + time) live.
   */
  private function sharedDraftStore(): SharedDraftStore {
    $store = $this->container->get('neo_alchemist.shared_draft_store');
    $this->assertInstanceOf(SharedDraftStore::class, $store, 'Premise: the shared draft store is registered.');
    return $store;
  }

  /**
   * Creates a real user so a uid resolves to a display name.
   */
  private function createEditor(string $name): User {
    $user = User::create(['name' => $name]);
    $user->save();
    return $user;
  }

  /**
   * Resolves a real ComponentTreeItem from a fresh test entity.
   */
  private function fieldItem(): ComponentTreeItem {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item, 'Premise: the entity resolved a component tree item.');
    return $item;
  }

}
