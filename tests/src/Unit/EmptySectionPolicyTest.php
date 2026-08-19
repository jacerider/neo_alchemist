<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An emptied section means two incompatible things, so it takes an argument.
 *
 * Both readings are correct in isolation:
 * - dependency detachment on a config-scope tree must *collapse* it, because
 *   the structure validator rejects an empty slot and an empty subtree;
 * - hybrid storage extraction must *preserve* it, because an empty flagged
 *   slot is the marker that says a creator deliberately emptied that region.
 *
 * Together they are a data-loss path, and it only appears where the two
 * subsystems meet: `drush neo:alchemist:integrity --detach` rewrites **entity**
 * rows, and a hybrid row is a storage subset. Collapsing one takes the flagged
 * sections with it, leaving `{root: []}` — which the next load reads as "this
 * entity was never customized" and answers by rendering the field default's
 * seed components. A maintenance command run for an unrelated deleted
 * component silently republishes seed content over a creator's page.
 *
 * The fix is not a better default. It is that the policy is a named argument
 * every call site chooses, and that the integrity command chooses per row from
 * the tree's own shape.
 *
 * @see \Drupal\neo_alchemist\EmptySectionPolicy
 * @see \Drupal\neo_alchemist\Drush\Commands\NeoAlchemistIntegrityCommands
 */
#[Group('neo_alchemist')]
final class EmptySectionPolicyTest extends UnitTestCase {

  /**
   * The reserved root uuid.
   */
  private const ROOT = ComponentTreeStructure::ROOT_UUID;

  /**
   * The field default layout: root → host; host.body → seed.
   */
  private function defaults(): array {
    return [
      'tree' => [
        self::ROOT => [['uuid' => 'host', 'component' => 'na_region_host']],
        'host' => ['body' => [['uuid' => 'seed', 'component' => 'na_leaf']]],
      ],
      'props' => [
        'host' => [],
        'seed' => ['text' => ['value' => 'SITE BUILDER SEED']],
      ],
    ];
  }

  /**
   * The anchor flagging host.body as entity-customizable.
   */
  private function anchors(): array {
    return ['host' => ['component' => 'na_region_host', 'slots' => ['body']]];
  }

  /**
   * A creator's stored row: the flagged region holds one deleted component.
   *
   * This is the shape a hybrid entity's column carries — an empty root, plus
   * the flagged sections. `doomed` is an instance of the component that the
   * maintenance command is detaching.
   */
  private function storedRow(): array {
    return [
      'tree' => [
        self::ROOT => [],
        'host' => ['body' => [['uuid' => 'doomed', 'component' => 'going_away']]],
      ],
      'props' => ['doomed' => ['text' => ['value' => 'AUTHORED']]],
    ];
  }

  /**
   * The cross-subsystem regression: detaching must not resurrect seed content.
   *
   * Preserve keeps `host.body` present but empty, so the merge on load reads
   * it as explicitly emptied and renders nothing there.
   */
  public function testDetachingFromHybridStorageKeepsTheRegionEmpty(): void {
    $after = ComponentTreeStructure::detachComponents(
      $this->storedRow(),
      ['going_away'],
      EmptySectionPolicy::Preserve,
    );

    $this->assertSame([], $after['tree']['host']['body'] ?? NULL, 'The flagged slot survives as an explicit empty marker.');

    $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $after['tree'], $after['props'], $this->anchors());

    $this->assertSame([], $merged['tree']['host']['body'], 'The region stays explicitly empty.');
    $this->assertNotContains('seed', ComponentTreeStructure::collectInstanceUuids($merged['tree']), 'The site builder seed was not republished.');
  }

  /**
   * Collapsing the same row is the data-loss path, demonstrated.
   *
   * Not an endorsement of the outcome — this is the behaviour that made the
   * policy an explicit argument, pinned so a future default cannot drift back
   * into it unnoticed.
   */
  public function testCollapsingHybridStorageIsWhatResurrectsSeedContent(): void {
    $after = ComponentTreeStructure::detachComponents(
      $this->storedRow(),
      ['going_away'],
      EmptySectionPolicy::Collapse,
    );

    $this->assertSame([self::ROOT => []], $after['tree'], 'Collapse leaves nothing but the root — indistinguishable from "never customized".');

    $storedTree = $after['tree'];
    unset($storedTree[self::ROOT]);
    $this->assertSame([], $storedTree, 'Nothing beyond the root, which the load path treats as a reset to the field default.');
  }

  /**
   * The integrity command picks the policy from the row's own shape.
   *
   * A config-scope default layout carries root-level instances; a hybrid
   * entity row does not. That single fact is the whole decision.
   */
  public function testStorageSubsetShapeSelectsThePolicy(): void {
    $this->assertTrue(
      ComponentTreeStructure::isStorageSubset($this->storedRow()['tree']),
      'A hybrid entity row is a storage subset, so Preserve applies.',
    );
    $this->assertFalse(
      ComponentTreeStructure::isStorageSubset($this->defaults()['tree']),
      'A full tree is not, so Collapse applies.',
    );
  }

  /**
   * Collapse is still what a config-scope default layout needs.
   *
   * The other half of the divergence: a default layout that kept an empty slot
   * would fail ComponentTreeStructureConstraintValidator on save, and the
   * config dependency system deletes a dependent it cannot fix — taking every
   * entity's stored values with the field.
   */
  public function testCollapseIsCorrectForConfigScope(): void {
    $values = [
      'tree' => [
        self::ROOT => [['uuid' => 'host', 'component' => 'na_region_host']],
        'host' => ['body' => [['uuid' => 'doomed', 'component' => 'going_away']]],
      ],
      'props' => ['host' => [], 'doomed' => []],
    ];

    $after = ComponentTreeStructure::detachComponents($values, ['going_away'], EmptySectionPolicy::Collapse);

    $this->assertArrayNotHasKey('host', $after['tree'], 'No empty slot and no empty subtree is left behind.');
    $this->assertSame([['uuid' => 'host', 'component' => 'na_region_host']], $after['tree'][self::ROOT], 'The host instance itself is untouched.');
  }

  /**
   * Preserve still removes the doomed instance's own section.
   *
   * Preserve is about slots and sections a removal *empties*, not about
   * keeping sections whose owner is gone — those are exactly the dangling
   * subtrees the validator rejects.
   */
  public function testPreserveStillDropsTheRemovedInstanceSection(): void {
    $values = [
      'tree' => [
        self::ROOT => [],
        'host' => ['body' => [['uuid' => 'doomed', 'component' => 'going_away']]],
        'doomed' => ['inner' => [['uuid' => 'child', 'component' => 'na_leaf']]],
      ],
      'props' => ['doomed' => [], 'child' => []],
    ];

    $after = ComponentTreeStructure::detachComponents($values, ['going_away'], EmptySectionPolicy::Preserve);

    $this->assertArrayNotHasKey('doomed', $after['tree'], 'The removed instance takes its own subtree with it.');
    $this->assertSame([], $after['tree']['host']['body'], 'Only the emptied slot is preserved.');
    $this->assertSame([], $after['props'], 'The descendant props went with the subtree.');
  }

}
