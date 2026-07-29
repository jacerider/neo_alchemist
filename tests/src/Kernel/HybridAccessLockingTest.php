<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hybrid mode locks inherited structure server-side.
 *
 * The editor chrome hides the buttons, but the access layer is the real
 * gate: inherited instances (the field default's structure) must refuse
 * every mutating operation, and creation must only be possible inside an
 * entity-customizable region. A creator silently editing site-builder-owned
 * structure is a second-order silent loss — those edits live outside the
 * stored subset and vanish on the next save.
 *
 * @see \Drupal\neo_alchemist\Entity\ComponentInstanceBase::checkHybridAccess()
 */
#[Group('neo_alchemist')]
class HybridAccessLockingTest extends HybridFieldKernelTestBase {

  /**
   * The inherited-instance refusal message.
   */
  private const INHERITED_REASON = 'This component is inherited from the field default layout.';

  /**
   * The non-customizable-target refusal message.
   */
  private const TARGET_REASON = 'Components may only be added within an entity-customizable region.';

  /**
   * Builds an entity with authored region content and returns its item.
   */
  private function itemWithAuthoredContent(): ComponentTreeItem {
    $entity = $this->createTestEntity();
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
   * Extracts the reason from a forbidden access result.
   */
  private function reason(mixed $result): ?string {
    return $result instanceof AccessResultReasonInterface ? $result->getReason() : NULL;
  }

  /**
   * Every mutating operation is forbidden on an inherited instance.
   */
  public function testInheritedInstanceIsLocked(): void {
    $item = $this->itemWithAuthoredContent();
    $host = $item->getComponent(static::HOST_UUID);
    $this->assertNotNull($host, 'Premise: the inherited host resolves.');
    $this->assertTrue($host->isInherited(), 'Premise: the host is inherited.');

    foreach (['update', 'delete', 'clone', 'sort', 'manage_value'] as $operation) {
      $result = $host->access($operation, NULL, TRUE);
      $this->assertTrue($result->isForbidden(), sprintf('Operation "%s" is forbidden on an inherited instance.', $operation));
      $this->assertSame(self::INHERITED_REASON, $this->reason($result), sprintf('Operation "%s" was refused by the hybrid gate specifically.', $operation));
    }
  }

  /**
   * Entity-owned instances inside the flagged region are not hybrid-locked.
   */
  public function testOwnedInstanceIsNotHybridLocked(): void {
    $item = $this->itemWithAuthoredContent();
    $owned = $item->getComponent('owned-leaf');
    $this->assertNotNull($owned, 'Premise: the authored leaf resolves.');
    $this->assertFalse($owned->isInherited(), 'Premise: the authored leaf is entity-owned.');

    // Only the ops entity_test's access handler can take: for an owned
    // instance the hybrid gate passes through to entity access, and
    // entity_test explodes on exotic ops like clone/manage_value. The
    // inherited test covers all five because the gate short-circuits first.
    foreach (['update', 'delete', 'sort'] as $operation) {
      $result = $owned->access($operation, NULL, TRUE);
      $this->assertNotSame(self::INHERITED_REASON, $this->reason($result), sprintf('Operation "%s" on an owned instance is never refused by the inherited gate.', $operation));
    }
  }

  /**
   * Creation is allowed only inside an entity-customizable region.
   */
  public function testCreateOnlyInsideCustomizableRegions(): void {
    $item = $this->itemWithAuthoredContent();
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    /** @var \Drupal\neo_alchemist\Entity\Component $leafComponent */
    $leafComponent = $storage->load('na_leaf');

    // Inside the flagged region: not refused by the hybrid target gate.
    $inRegion = $item->createComponent($leafComponent, static::HOST_UUID, 'body');
    $this->assertNotSame(self::TARGET_REASON, $this->reason($inRegion->access('create', NULL, TRUE)), 'Creating inside the flagged region passes the hybrid target gate.');

    // At the tree root: forbidden — the root is never a custom target.
    // (Targeting a slot the parent does not declare at all trips the loud
    // in-code assert before access is ever consulted; the unflagged-slot
    // predicate itself is pinned in testRootCanNeverBeTargeted.)
    $atRoot = $item->createComponent($leafComponent);
    $rootResult = $atRoot->access('create', NULL, TRUE);
    $this->assertTrue($rootResult->isForbidden(), 'Creating at the root is forbidden in hybrid mode.');
    $this->assertSame(self::TARGET_REASON, $this->reason($rootResult));
  }

  /**
   * The root can never be targeted for creation.
   */
  public function testRootCanNeverBeTargeted(): void {
    $item = $this->itemWithAuthoredContent();

    $this->assertFalse($item->isCustomTarget(NULL, NULL), 'NULL parent (the root) is not a custom target.');
    $this->assertTrue($item->isCustomTarget(static::HOST_UUID, 'body'), 'The flagged region is a custom target.');
    $this->assertFalse($item->isCustomTarget(static::HOST_UUID, 'other'), 'An unflagged slot is not a custom target.');
    $this->assertFalse($item->isCustomTarget(static::SEED_UUID, 'body'), 'A non-anchor owner is not a custom target.');
  }

}
