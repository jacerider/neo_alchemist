<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Routing\EditorOp;
use Drupal\neo_alchemist\Routing\EditorOpInventory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The eight editor ops are one declared table, not four derivations.
 *
 * "An operation on a component in the editor" — edit, sort, clone, delete, add
 * before, add after, move up, move down — had its vocabulary derived four
 * times, in three languages, and nothing checked that the four still agreed.
 * The client alone knew that "add-before" decomposes into a verb (add) and a
 * position (before) by splitting the identifier on a hyphen at runtime, so the
 * op ids were load-bearing strings nobody declared were load-bearing.
 *
 * This pins the one table that now declares that vocabulary: for each op its
 * identifier, verb, position OR direction, the route rel it targets, its label
 * and its icon. The expected values are the strings the editor chrome renders
 * today (@see template_preprocess_neo_alchemist_overlay and
 * \Drupal\neo_alchemist\Entity\Component::prepareRenderableForPreview) — an
 * independent, hand-authored source of truth, so this is a specification of the
 * vocabulary rather than a mirror of the class.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorOpInventory
 */
#[Group('neo_alchemist')]
final class EditorOpInventoryTest extends UnitTestCase {

  /**
   * The inventory under test.
   */
  private EditorOpInventory $inventory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->inventory = new EditorOpInventory();
  }

  /**
   * The inventory declares exactly the eight ops the entity emits, in order.
   *
   * The eight are the keys of the entity's ops map. The order here is the order
   * the chrome renders them: the four verb ops first (the bottom bar's "acting
   * on the component itself" group), then the four positional ops.
   */
  public function testDeclaresTheEightOps(): void {
    $this->assertSame([
      'edit',
      'sort',
      'clone',
      'delete',
      'add-before',
      'add-after',
      'move-up',
      'move-down',
    ], array_keys($this->inventory->ops()));
  }

  /**
   * Every op declares its verb, position/direction, rel, label and icon.
   *
   * The expected row for each op is what the chrome renders today, restated
   * once. `position` and `direction` are the decomposition the client used to
   * infer by splitting the identifier — add ops carry a position (a sibling
   * relationship: before/after), move ops carry a direction (up/down), and the
   * four verb ops carry neither. `rel` is the route-family member op the op's
   * URL targets: add is the library route with a position, move is the move
   * route with a direction, and the rest name their own route. `icon` is NULL
   * for the four verb ops because the chrome passes no explicit icon for them —
   * it lets neo_icon_admin() derive one from the label — so declaring an icon
   * here would change what renders.
   */
  public function testEachOpDeclaresItsFields(): void {
    foreach ($this->expectedOps() as $id => $expected) {
      $op = $this->inventory->getOp($id);
      $this->assertInstanceOf(EditorOp::class, $op);
      $this->assertSame($id, $op->id, "$id id");
      $this->assertSame($expected['verb'], $op->verb, "$id verb");
      $this->assertSame($expected['position'], $op->position, "$id position");
      $this->assertSame($expected['direction'], $op->direction, "$id direction");
      $this->assertSame($expected['rel'], $op->rel, "$id rel");
      $this->assertSame($expected['label'], $op->label, "$id label");
      $this->assertSame($expected['short'], $op->short, "$id short label");
      $this->assertSame($expected['icon'], $op->icon, "$id icon");
    }
  }

  /**
   * The getOp() accessor returns one record and rejects an unknown id.
   */
  public function testGetOpReturnsOneRecordAndRejectsUnknown(): void {
    $this->assertSame('library', $this->inventory->getOp('add-after')->rel);
    $this->expectException(\InvalidArgumentException::class);
    $this->inventory->getOp('teleport');
  }

  /**
   * The eight ops with the vocabulary the chrome renders today.
   *
   * @return array<string, array<string, string|null>>
   *   Op id => expected field values.
   */
  private function expectedOps(): array {
    return [
      'edit' => [
        'verb' => 'edit',
        'position' => NULL,
        'direction' => NULL,
        'rel' => 'edit',
        'label' => 'Edit',
        'short' => NULL,
        'icon' => NULL,
      ],
      'sort' => [
        'verb' => 'sort',
        'position' => NULL,
        'direction' => NULL,
        'rel' => 'sort',
        'label' => 'Sort',
        'short' => NULL,
        'icon' => NULL,
      ],
      'clone' => [
        'verb' => 'clone',
        'position' => NULL,
        'direction' => NULL,
        'rel' => 'clone',
        'label' => 'Clone',
        'short' => NULL,
        'icon' => NULL,
      ],
      'delete' => [
        'verb' => 'delete',
        'position' => NULL,
        'direction' => NULL,
        'rel' => 'delete',
        'label' => 'Delete',
        'short' => NULL,
        'icon' => NULL,
      ],
      'add-before' => [
        'verb' => 'add',
        'position' => 'before',
        'direction' => NULL,
        'rel' => 'library',
        'label' => 'Add Component Before',
        'short' => 'Before',
        'icon' => 'plus',
      ],
      'add-after' => [
        'verb' => 'add',
        'position' => 'after',
        'direction' => NULL,
        'rel' => 'library',
        'label' => 'Add Component After',
        'short' => 'After',
        'icon' => 'plus',
      ],
      'move-up' => [
        'verb' => 'move',
        'position' => NULL,
        'direction' => 'up',
        'rel' => 'move',
        'label' => 'Move Component Up',
        'short' => NULL,
        'icon' => 'arrow-up',
      ],
      'move-down' => [
        'verb' => 'move',
        'position' => NULL,
        'direction' => 'down',
        'rel' => 'move',
        'label' => 'Move Component Down',
        'short' => NULL,
        'icon' => 'arrow-down',
      ],
    ];
  }

}
