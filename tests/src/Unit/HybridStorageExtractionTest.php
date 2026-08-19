<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the hybrid compose/extract pair at the array-algebra level.
 *
 * Both are pure functions of a default layout, a stored subset and a set of
 * anchors. They used to be entangled with the field item — only by where they
 * read their inputs and where they stashed their output — which meant reaching
 * them took 194 lines of test-only subclasses, one of which needed a mock that
 * had to contradict itself: report that the field had no component values (to
 * keep the constructor's typed-data path out of play) while simultaneously
 * returning a full default layout. Those classes are gone; these tests call
 * what production calls, with three arrays.
 *
 * The two invariants with silent-data-loss consequences:
 * - Un-flagging a region (removing region_custom while the component stays in
 *   the default layout) must preserve the entity's authored region content as
 *   orphans — previously it was neither merged nor stashed, so the next save
 *   destroyed it irrecoverably. Deleting the component from the layout already
 *   preserved content; un-flagging is the more obviously revertible change of
 *   the two and lost it.
 * - Every tuple in the storage subset needs a props entry. The parity guard
 *   previously excluded exactly the container uuids it was meant to protect,
 *   and parity is a postcondition of extraction now rather than a backfill the
 *   caller performs to appease the save-time check.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::composeHybrid()
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::extractHybridStorage()
 */
#[Group('neo_alchemist')]
class HybridStorageExtractionTest extends UnitTestCase {

  /**
   * The standard default layout: root → host, host.body → seed.
   */
  private function defaults(): array {
    return [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'host', 'component' => 'na_region_host'],
        ],
        'host' => [
          'body' => [['uuid' => 'seed', 'component' => 'na_leaf']],
        ],
      ],
      'props' => [
        'host' => ['heading' => ['value' => 'DEFAULT HEADING']],
        'seed' => ['text' => ['value' => 'SEED']],
      ],
    ];
  }

  /**
   * The host anchor flagging its body region.
   */
  private function hostAnchor(): array {
    return [
      'host' => ['component' => 'na_region_host', 'slots' => ['body']],
    ];
  }

  /**
   * Un-flagging an anchor reports the stored slot content as an orphan.
   */
  public function testComposeReportsUnflaggedAnchorSlotAsOrphan(): void {
    // region_custom was removed: no anchors, but host is still in the layout.
    $storedTree = [
      'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
    ];
    $storedProps = ['authored' => ['text' => ['value' => 'AUTHORED']]];

    $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $storedTree, $storedProps, []);

    $this->assertSame(
      [['uuid' => 'authored', 'component' => 'na_leaf']],
      $merged['orphans']['tree']['host']['body'] ?? NULL,
      'The un-flagged slot content was reported as an orphan instead of being dropped.',
    );
    $this->assertSame(
      ['text' => ['value' => 'AUTHORED']],
      $merged['orphans']['props']['authored'] ?? NULL,
      'The orphaned tuple kept its props.',
    );
    // The merged runtime value stays the pure default: orphans are
    // render-inert.
    $this->assertSame($this->defaults()['tree'], $merged['tree'], 'The merged tree is the unmodified default layout.');
  }

  /**
   * A full merged tree passing through composes without phantom orphans.
   *
   * The hybrid setter also receives full merged trees (in-session edits,
   * drafts). Non-anchor sections that are byte-identical to the default layout
   * are default structure, not entity content — reporting them as orphans
   * would copy the whole default into every entity's storage.
   */
  public function testComposeSkipsSectionsIdenticalToDefault(): void {
    $defaults = $this->defaults();
    // A second, non-anchor container in the default layout.
    $defaults['tree'][ComponentTreeStructure::ROOT_UUID][] = ['uuid' => 'other', 'component' => 'na_two_region'];
    $defaults['tree']['other'] = [
      'top' => [['uuid' => 'deepseed', 'component' => 'na_leaf']],
    ];
    $defaults['props'] += ['other' => [], 'deepseed' => ['text' => ['value' => 'DEEP']]];

    // The incoming value is the full merged tree: authored anchor content plus
    // the default's own sections, verbatim.
    $storedTree = [
      'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
      'other' => $defaults['tree']['other'],
    ];
    $storedProps = [
      'authored' => ['text' => ['value' => 'AUTHORED']],
      'deepseed' => ['text' => ['value' => 'DEEP']],
    ];

    $merged = ComponentTreeStructure::composeHybrid($defaults, $storedTree, $storedProps, $this->hostAnchor());

    $this->assertSame(['tree' => [], 'props' => []], $merged['orphans'], 'Nothing was reported for default-identical sections or anchored slots.');
    $this->assertSame($storedTree['host']['body'], $merged['tree']['host']['body'], 'The anchored slot carries the authored content.');
  }

  /**
   * Un-flagging one of two regions orphans only that region's content.
   */
  public function testComposePartialUnflagOrphansOnlyUnflaggedSlot(): void {
    $defaults = [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'duo', 'component' => 'na_two_region'],
        ],
        'duo' => [
          'top' => [['uuid' => 'seed_t', 'component' => 'na_leaf']],
          'bottom' => [['uuid' => 'seed_b', 'component' => 'na_leaf']],
        ],
      ],
      'props' => [
        'duo' => [],
        'seed_t' => ['text' => ['value' => 'SEED TOP']],
        'seed_b' => ['text' => ['value' => 'SEED BOTTOM']],
      ],
    ];
    // Only `top` is still flagged; `bottom` was un-flagged after the entity
    // stored content in both.
    $anchors = ['duo' => ['component' => 'na_two_region', 'slots' => ['top']]];

    $storedTree = [
      'duo' => [
        'top' => [['uuid' => 't1', 'component' => 'na_leaf']],
        'bottom' => [['uuid' => 'b1', 'component' => 'na_leaf']],
      ],
    ];
    $storedProps = [
      't1' => ['text' => ['value' => 'AUTHORED TOP']],
      'b1' => ['text' => ['value' => 'AUTHORED BOTTOM']],
    ];

    $merged = ComponentTreeStructure::composeHybrid($defaults, $storedTree, $storedProps, $anchors);

    $this->assertSame(
      ['bottom' => [['uuid' => 'b1', 'component' => 'na_leaf']]],
      $merged['orphans']['tree']['duo'] ?? NULL,
      'Only the un-flagged slot was orphaned.',
    );
    $this->assertArrayHasKey('b1', $merged['orphans']['props']);
    $this->assertArrayNotHasKey('t1', $merged['orphans']['props'], 'The still-flagged slot content is merged, not orphaned.');
    $this->assertSame($storedTree['duo']['top'], $merged['tree']['duo']['top'], 'The still-flagged slot merged the authored content.');
    $this->assertSame($defaults['tree']['duo']['bottom'], $merged['tree']['duo']['bottom'], 'The un-flagged slot renders the default seed again.');
  }

  /**
   * An anchor added after the last save keeps rendering its default seeds.
   *
   * "Absent from storage" and "stored but empty" are different answers: the
   * first means the anchor postdates the value, the second means a creator
   * emptied the region.
   */
  public function testComposeKeepsSeedsForAnchorsAbsentFromStorage(): void {
    $merged = ComponentTreeStructure::composeHybrid($this->defaults(), [], [], $this->hostAnchor());

    $this->assertSame($this->defaults()['tree'], $merged['tree'], 'The seed children apply.');
    $this->assertSame(['tree' => [], 'props' => []], $merged['orphans']);
  }

  /**
   * An explicitly emptied flagged slot renders nothing, not the seed.
   *
   * The slot stays present-and-empty rather than being dropped. "Absent" is
   * already spoken for — it means the anchor postdates the stored value, and
   * compose answers that by applying the seed children. A merged value that
   * dropped the key therefore re-seeded the region the next time it was
   * composed, which is what a second draft save does.
   */
  public function testComposeHonoursAnExplicitlyEmptiedSlot(): void {
    $merged = ComponentTreeStructure::composeHybrid(
      $this->defaults(),
      ['host' => ['body' => []]],
      [],
      $this->hostAnchor(),
    );

    $this->assertSame([], $merged['tree']['host']['body'], 'The slot is present and empty: nothing renders there.');
    $this->assertArrayNotHasKey('seed', $merged['props'], 'The seed closure was dropped along with its content.');
    $this->assertSame([], ComponentTreeStructure::collectAnchorClosure($merged['tree'], $this->hostAnchor()));
  }

  /**
   * Extract re-emits orphan slots without overwriting live ones.
   *
   * A partially-anchored owner already has a storage section for its flagged
   * slots, so the re-emit must be slot-granular — a whole-section isset()
   * guard never fires and the orphan is lost on save.
   */
  public function testExtractReEmitsOrphanSlotsWithoutOverwriting(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'host', 'component' => 'na_region_host'],
      ],
      'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
    ];
    $props = ['authored' => ['text' => ['value' => 'AUTHORED']]];
    $orphans = [
      'tree' => ['host' => ['gone_slot' => [['uuid' => 'x1', 'component' => 'na_leaf']]]],
      'props' => ['x1' => ['text' => ['value' => 'ORPHANED']]],
    ];

    $storage = ComponentTreeStructure::extractHybridStorage($tree, $props, $this->hostAnchor(), $orphans);

    $this->assertSame(
      [['uuid' => 'authored', 'component' => 'na_leaf']],
      $storage['tree']['host']['body'] ?? NULL,
      'The live anchored slot was written from the merged value, not overwritten by orphans.',
    );
    $this->assertSame(
      [['uuid' => 'x1', 'component' => 'na_leaf']],
      $storage['tree']['host']['gone_slot'] ?? NULL,
      'The orphaned slot was re-emitted alongside the live one.',
    );
    $this->assertSame(['text' => ['value' => 'ORPHANED']], $storage['props']['x1'] ?? NULL);
  }

  /**
   * Every stored tuple gets a props entry; anchor owners get none.
   *
   * ComponentTreeItem::preSave() throws a LogicException when the tree and
   * props uuids diverge, so a container tuple without props must be
   * backfilled — and owner section keys (which are not instances in the
   * subset) must not be.
   */
  public function testExtractBackfillsPropsForContainerTuples(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'host', 'component' => 'na_region_host'],
      ],
      'host' => ['body' => [['uuid' => 'container', 'component' => 'na_two_region']]],
      'container' => ['top' => [['uuid' => 'leaf', 'component' => 'na_leaf']]],
    ];
    // The container deliberately has no props entry; only its leaf does.
    $props = ['leaf' => ['text' => ['value' => 'DEEP AUTHORED']]];

    $storage = ComponentTreeStructure::extractHybridStorage($tree, $props, $this->hostAnchor());

    $this->assertArrayHasKey('container', $storage['props'], 'The props-less container tuple was backfilled to keep tree/props parity.');
    $this->assertSame([], $storage['props']['container']);
    $this->assertSame(['text' => ['value' => 'DEEP AUTHORED']], $storage['props']['leaf'] ?? NULL);
    $this->assertArrayNotHasKey('host', $storage['props'], 'Anchor owners are section keys, not instances — no props entry is invented for them.');
  }

  /**
   * The storage subset always carries an empty root section.
   *
   * That empty root is the discriminant the load path branches on: it is what
   * marks the value as an authoritative subset rather than an in-session
   * merged tree.
   */
  public function testExtractAlwaysWritesAnEmptyRoot(): void {
    $storage = ComponentTreeStructure::extractHybridStorage(
      $this->defaults()['tree'],
      $this->defaults()['props'],
      $this->hostAnchor(),
    );

    $this->assertSame([], $storage['tree'][ComponentTreeStructure::ROOT_UUID]);
    $this->assertTrue(ComponentTreeStructure::isStorageSubset($storage['tree']));
  }

  /**
   * An emptied flagged slot is still written, as the explicit empty marker.
   */
  public function testExtractWritesEveryFlaggedSlotEvenWhenEmpty(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'host', 'component' => 'na_region_host'],
      ],
    ];

    $storage = ComponentTreeStructure::extractHybridStorage($tree, [], $this->hostAnchor());

    $this->assertSame([], $storage['tree']['host']['body'] ?? NULL);
  }

}
