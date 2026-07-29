<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the hybrid compose/extract pair at the array-algebra level.
 *
 * The two invariants with silent-data-loss consequences:
 * - Un-flagging a region (removing region_custom while the component stays
 *   in the default layout) must preserve the entity's authored region
 *   content as orphans — previously it was neither merged nor stashed, so
 *   the next save destroyed it irrecoverably. Deleting the component from
 *   the layout already preserved content; un-flagging is the more obviously
 *   revertible change of the two and lost it.
 * - Every tuple in the storage subset needs a props entry
 *   (ComponentTreeItem::preSave() throws a LogicException otherwise). The
 *   parity guard previously excluded exactly the container uuids it was
 *   meant to protect.
 *
 * Red/green proof performed during development: with the pre-fix
 * whole-section orphan skip (isset($defaultFlip[$key])) and the inverted
 * parity-guard clause restored,
 * testComposeStashesUnflaggedAnchorSlotAsOrphan,
 * testComposePartialUnflagStashesOnlyUnflaggedSlot,
 * testExtractReEmitsOrphanSlotsWithoutOverwriting and
 * testExtractBackfillsPropsForContainerTuples go red.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList::composeHybridValue()
 * @see \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList::extractHybridStorageValue()
 */
#[Group('neo_alchemist')]
class HybridStorageExtractionTest extends UnitTestCase {

  /**
   * Builds the exposed list with a mocked field definition.
   *
   * @param array $anchors
   *   The custom-region anchors the definition reports.
   * @param array $defaults
   *   The field default layout ('tree' + 'props').
   */
  private function buildList(array $anchors, array $defaults): TestHybridComposerList {
    $definition = $this->createMock(ComponentFieldConfigInterface::class);
    // FALSE keeps the constructor's appendItem() path — which needs the
    // typed-data container — out of play.
    $definition->method('hasComponentValues')->willReturn(FALSE);
    $definition->method('getCustomRegions')->willReturn($anchors);
    $definition->method('getComponentValues')->willReturn($defaults);
    return new TestHybridComposerList($definition);
  }

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
   * Un-flagging an anchor stashes the stored slot content as orphans.
   */
  public function testComposeStashesUnflaggedAnchorSlotAsOrphan(): void {
    // region_custom was removed: no anchors, but host is still in the layout.
    $list = $this->buildList([], $this->defaults());

    $storedTree = [
      'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
    ];
    $storedProps = ['authored' => ['text' => ['value' => 'AUTHORED']]];

    $merged = $list->composeHybridValuePublic($storedTree, $storedProps);
    $orphans = $list->getHybridOrphans();

    $this->assertSame(
      [['uuid' => 'authored', 'component' => 'na_leaf']],
      $orphans['tree']['host']['body'] ?? NULL,
      'The un-flagged slot content was stashed as an orphan instead of being dropped.',
    );
    $this->assertSame(
      ['text' => ['value' => 'AUTHORED']],
      $orphans['props']['authored'] ?? NULL,
      'The orphaned tuple kept its props.',
    );
    // The merged runtime value stays the pure default: orphans are
    // render-inert.
    $this->assertSame($this->defaults()['tree'], json_decode($merged['tree'], TRUE), 'The merged tree is the unmodified default layout.');
  }

  /**
   * A full merged tree passing through composes without phantom orphans.
   *
   * The hybrid setter also receives full merged trees (in-session edits,
   * drafts). Non-anchor sections that are byte-identical to the default
   * layout are default structure, not entity content — stashing them would
   * copy the whole default into every entity's storage.
   */
  public function testComposeSkipsSectionsIdenticalToDefault(): void {
    $defaults = $this->defaults();
    // A second, non-anchor container in the default layout.
    $defaults['tree'][ComponentTreeStructure::ROOT_UUID][] = ['uuid' => 'other', 'component' => 'na_two_region'];
    $defaults['tree']['other'] = [
      'top' => [['uuid' => 'deepseed', 'component' => 'na_leaf']],
    ];
    $defaults['props'] += ['other' => [], 'deepseed' => ['text' => ['value' => 'DEEP']]];

    $list = $this->buildList($this->hostAnchor(), $defaults);

    // The incoming value is the full merged tree: authored anchor content
    // plus the default's own sections, verbatim.
    $storedTree = [
      'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
      'other' => $defaults['tree']['other'],
    ];
    $storedProps = [
      'authored' => ['text' => ['value' => 'AUTHORED']],
      'deepseed' => ['text' => ['value' => 'DEEP']],
    ];

    $merged = $list->composeHybridValuePublic($storedTree, $storedProps);
    $orphans = $list->getHybridOrphans();

    $this->assertSame(['tree' => [], 'props' => []], $orphans, 'Nothing was stashed for default-identical sections or anchored slots.');
    $mergedTree = json_decode($merged['tree'], TRUE);
    $this->assertSame($storedTree['host']['body'], $mergedTree['host']['body'], 'The anchored slot carries the authored content.');
  }

  /**
   * Un-flagging one of two regions stashes only that region's content.
   */
  public function testComposePartialUnflagStashesOnlyUnflaggedSlot(): void {
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
    $list = $this->buildList($anchors, $defaults);

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

    $merged = $list->composeHybridValuePublic($storedTree, $storedProps);
    $orphans = $list->getHybridOrphans();

    $this->assertSame(
      ['bottom' => [['uuid' => 'b1', 'component' => 'na_leaf']]],
      $orphans['tree']['duo'] ?? NULL,
      'Only the un-flagged slot was stashed.',
    );
    $this->assertArrayHasKey('b1', $orphans['props']);
    $this->assertArrayNotHasKey('t1', $orphans['props'], 'The still-flagged slot content is merged, not orphaned.');
    $mergedTree = json_decode($merged['tree'], TRUE);
    $this->assertSame($storedTree['duo']['top'], $mergedTree['duo']['top'], 'The still-flagged slot merged the authored content.');
    $this->assertSame($defaults['tree']['duo']['bottom'], $mergedTree['duo']['bottom'], 'The un-flagged slot renders the default seed again.');
  }

  /**
   * Extract re-emits orphan slots without overwriting live ones.
   *
   * A partially-anchored owner already has a storage section for its flagged
   * slots, so the re-emit must be slot-granular — a whole-section isset()
   * guard never fires and the orphan is lost on save.
   */
  public function testExtractReEmitsOrphanSlotsWithoutOverwriting(): void {
    $list = $this->buildList($this->hostAnchor(), $this->defaults());
    $list->setStubItemValue([
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'host', 'component' => 'na_region_host'],
        ],
        'host' => ['body' => [['uuid' => 'authored', 'component' => 'na_leaf']]],
      ],
      'props' => ['authored' => ['text' => ['value' => 'AUTHORED']]],
    ]);
    $list->setHybridOrphans([
      'tree' => ['host' => ['gone_slot' => [['uuid' => 'x1', 'component' => 'na_leaf']]]],
      'props' => ['x1' => ['text' => ['value' => 'ORPHANED']]],
    ]);

    $storage = $list->extractHybridStorageValuePublic();

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
    $list = $this->buildList($this->hostAnchor(), $this->defaults());
    $list->setStubItemValue([
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'host', 'component' => 'na_region_host'],
        ],
        'host' => ['body' => [['uuid' => 'container', 'component' => 'na_two_region']]],
        'container' => ['top' => [['uuid' => 'leaf', 'component' => 'na_leaf']]],
      ],
      // The container deliberately has no props entry; only its leaf does.
      'props' => ['leaf' => ['text' => ['value' => 'DEEP AUTHORED']]],
    ]);

    $storage = $list->extractHybridStorageValuePublic();

    $this->assertArrayHasKey('container', $storage['props'], 'The props-less container tuple was backfilled to keep tree/props parity.');
    $this->assertSame([], $storage['props']['container']);
    $this->assertSame(['text' => ['value' => 'DEEP AUTHORED']], $storage['props']['leaf'] ?? NULL);
    $this->assertArrayNotHasKey('host', $storage['props'], 'Anchor owners are section keys, not instances — no props entry is invented for them.');
  }

  /**
   * The composed props JSON is always an object, never a bare array.
   *
   * ComponentPropsValues::setValue() asserts the JSON starts with '{', and
   * an empty PHP array encodes to '[]'.
   */
  public function testPropsEncodeAsJsonObjectWhenEmpty(): void {
    $defaults = [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'host', 'component' => 'na_region_host'],
        ],
        'host' => ['body' => []],
      ],
      'props' => [],
    ];
    $list = $this->buildList($this->hostAnchor(), $defaults);

    $merged = $list->composeHybridValuePublic(
      ['host' => ['body' => []]],
      [],
    );

    $this->assertSame('{}', $merged['props'], 'Empty props encode as a JSON object.');
    $this->assertStringStartsWith('{', $merged['tree'], 'The tree JSON is an object.');
  }

  /**
   * The tuple-uuid helper excludes section-only keys.
   */
  public function testTreeTupleUuidsExcludesSectionOnlyKeys(): void {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [],
      'host' => ['body' => [['uuid' => 'container', 'component' => 'c']]],
      'container' => ['top' => [['uuid' => 'leaf', 'component' => 'l']]],
    ];

    $uuids = TestHybridComposerList::getTreeTupleUuidsPublic($tree);
    sort($uuids);

    $this->assertSame(['container', 'leaf'], $uuids, 'Only tuple uuids are returned — host is a section-only key.');
  }

}
