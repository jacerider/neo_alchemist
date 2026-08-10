<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Restructuring the tree is gated on updating the host, nothing else.
 *
 * Removing, duplicating or reordering an instance rewrites one field value on
 * the host entity: it is an edit of the host, never a delete of it, and never
 * an operation the host's own access handler has heard of. Passing the op name
 * straight through made 'delete' demand delete-the-host permission to drop a
 * single component, and left 'clone' hitting a handler with no opinion — so it
 * came back neutral and only accounts bypassing access entirely ever saw the
 * button. Both failures are invisible to an administrator, which is what let
 * this stand.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::access()
 */
#[Group('neo_alchemist')]
class ComponentTreeOperationMappingTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * The tree-restructuring operations, all of which are host edits.
   */
  private const RESTRUCTURING_OPS = ['update', 'delete', 'clone', 'sort'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Reserves uid 0 and uid 1, so every account below is an ordinary user
    // rather than the superuser — whose bypass is precisely what masked this.
    $this->setUpCurrentUser();
  }

  /**
   * Builds an entity owned by the account, with authored region content.
   */
  private function ownedItem(UserInterface $account): ComponentTreeItem {
    // A label is required, not decoration: entity_test's access handler
    // explodes the label when asked about an operation it does not know, so a
    // nameless entity would TypeError on the 'clone' premise below rather than
    // returning the neutral this test is pinning.
    $entity = EntityTest::create([
      'name' => 'Owned host',
      'user_id' => $account->id(),
    ]);
    $entity->save();
    $entity = $this->reloadEntity($entity);
    $this->assertFieldIsHybrid($entity);
    $this->authorRegionContent($entity, [
      ['uuid' => 'owned-leaf', 'component' => 'na_leaf'],
    ], [
      'owned-leaf' => $this->leafProps('OWNED'),
    ]);
    $item = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  /**
   * An account that may edit the host, but not delete it, may edit the tree.
   */
  public function testEditingHostIsEnoughToRestructureTree(): void {
    // 'edit own entity_test content' grants update on an owned entity and
    // nothing else — the separation this test needs.
    $editor = $this->createUser(['view test entity', 'edit own entity_test content']);
    $item = $this->ownedItem($editor);
    $host = $item->getEntity();

    // Premise: the host itself answers yes to update and no to everything
    // else. Without this split the assertions below prove nothing.
    $this->assertTrue($host->access('update', $editor), 'Premise: the account may edit the host entity.');
    $this->assertFalse($host->access('delete', $editor), 'Premise: the account may not delete the host entity.');
    $this->assertFalse($host->access('clone', $editor), 'Premise: the host has no opinion on a "clone" operation.');

    $owned = $item->getComponent('owned-leaf');
    $this->assertNotNull($owned, 'Premise: the authored leaf resolves.');
    $this->assertFalse($owned->isInherited(), 'Premise: the leaf is entity-owned, so the hybrid gate stays out of the way.');

    foreach (self::RESTRUCTURING_OPS as $operation) {
      $this->assertTrue($item->access($operation, $editor), sprintf('Field item allows "%s" for an account that may edit the host.', $operation));
      $this->assertTrue($owned->access($operation, $editor), sprintf('Instance allows "%s" for an account that may edit the host.', $operation));
    }
  }

  /**
   * An account that may only view the host may not restructure the tree.
   */
  public function testViewingHostIsNotEnoughToRestructureTree(): void {
    $owner = $this->createUser(['view test entity', 'edit own entity_test content']);
    $viewer = $this->createUser(['view test entity']);
    $item = $this->ownedItem($owner);

    $this->assertFalse($item->getEntity()->access('update', $viewer), 'Premise: the viewer may not edit the host entity.');

    $owned = $item->getComponent('owned-leaf');
    $this->assertNotNull($owned, 'Premise: the authored leaf resolves.');

    foreach (self::RESTRUCTURING_OPS as $operation) {
      $this->assertFalse($item->access($operation, $viewer), sprintf('Field item refuses "%s" for an account that may not edit the host.', $operation));
      $this->assertFalse($owned->access($operation, $viewer), sprintf('Instance refuses "%s" for an account that may not edit the host.', $operation));
    }
  }

}
