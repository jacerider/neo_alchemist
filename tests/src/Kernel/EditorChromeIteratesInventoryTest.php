<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Routing\EditorOp;
use Drupal\neo_alchemist\Routing\EditorOpInventory;
use Drupal\neo_icon\IconElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * The editor chrome iterates the op inventory rather than declaring the ops.
 *
 * The overlay's preprocess used to name the eight ops by hand and emit the
 * four positional ones as individual variables (op_add_before and three
 * siblings) that the template reached for by name. It now iterates
 * EditorOpInventory::ops() and names no op: _neo_alchemist_overlay_ops() is
 * the seam that projects the op vocabulary into the render surfaces the
 * templates loop over — the outline's edge knobs (`outline_ops`), the rail
 * beside the selection (`outline_rail`) and the inspector's ⋯ menu
 * (`inspector_menu`).
 *
 * These assert that projection against the strings the chrome renders today
 * (an independent, hand-authored source of truth), and that a row added to the
 * inventory becomes a button with no edit to the preprocess or the template —
 * the point of the ticket, demonstrated rather than assumed.
 *
 * The chrome reads the static vocabulary only: nothing here touches the
 * per-component op records (permission and resolved URL), so it is green
 * whether or not the payload work has landed.
 *
 * @see template_preprocess_neo_alchemist_overlay
 * @see _neo_alchemist_overlay_ops
 * @see \Drupal\neo_alchemist\Routing\EditorOpInventory
 */
#[Group('neo_alchemist')]
class EditorChromeIteratesInventoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The neo_icon module supplies neo_icon_admin(), which builds each button's
   * icon title.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_icon',
  ];

  /**
   * The op vocabulary the chrome projects.
   */
  private function inventory(): EditorOpInventory {
    return $this->container->get('neo_alchemist.editor_op_inventory');
  }

  /**
   * The chrome the preprocess builds from a set of op records.
   *
   * @param \Drupal\neo_alchemist\Routing\EditorOp[] $ops
   *   The op records, in render order.
   *
   * @return array
   *   The `outline_ops`, `outline_rail` and `inspector_menu` render
   *   structures.
   */
  private function chrome(array $ops): array {
    return _neo_alchemist_overlay_ops($ops);
  }

  /**
   * The rail and the ⋯ menu hold the moves, then the verbs but Edit.
   *
   * A move carries a direction, a verb neither; Edit is the inspector itself,
   * so it has no button. Both surfaces read the same order with one rule,
   * before the first verb. On the rail each is an icon named in a tooltip to
   * its right; in the menu each is labelled. Delete is the one marked as
   * destructive; an op with shortcuts carries them.
   */
  public function testMovesThenVerbsOnTheRailAndInTheMenu(): void {
    $chrome = $this->chrome($this->inventory()->ops());
    $expected = ['move-up', 'move-down', 'clone', 'delete'];
    $labels = [
      'move-up' => 'Move component up',
      'move-down' => 'Move component down',
      'clone' => 'Duplicate',
      'delete' => 'Delete',
    ];
    $keys = [
      'move-up' => 'Alt+ArrowUp',
      'move-down' => 'Alt+ArrowDown',
      'clone' => 'Mod+d',
      'delete' => 'Delete Backspace',
    ];

    foreach (['outline_rail', 'inspector_menu'] as $surface) {
      $this->assertSame($expected, array_keys($chrome[$surface]), "$surface: moves, then the verbs but edit.");
      foreach ($chrome[$surface] as $id => $item) {
        $link = $item['link'];
        $this->assertSame($id === 'clone', $item['divider'], "$surface: the rule comes before the first verb only ($id).");
        $this->assertSame('link', $link['#type'], "$surface: $id is a link");
        $this->assertSame($id, $link['#attributes']['data-op'], "$surface: $id data-op");
        $this->assertSame($keys[$id], $link['#attributes']['data-op-keys'], "$surface: $id shortcuts");
        $classes = $link['#attributes']['class'];
        $this->assertContains('neo-alchemist--op', $classes, "$surface: $id is an op");
        $this->assertSame($id === 'delete', in_array('is-danger', $classes, TRUE), "$surface: only delete destroys");
        $this->assertInstanceOf(IconElement::class, $link['#title']);
        $this->assertSame($labels[$id], (string) $link['#title']->getText(), "$surface: $id label");
      }
    }
    foreach ($chrome['outline_rail'] as $id => $item) {
      $this->assertTrue($item['link']['#title']->isIconOnly(), "Rail: $id is an icon.");
      $this->assertSame('right', $item['link']['#tooltip_options']['placement'], "Rail: $id is named to its right.");
    }
    foreach ($chrome['inspector_menu'] as $id => $item) {
      $this->assertFalse($item['link']['#title']->isIconOnly(), "Menu: $id is labelled.");
      $this->assertSame('menuitem', $item['link']['#attributes']['role'], "Menu: $id is a menu item.");
    }
  }

  /**
   * The ops that insert are the outline's knobs, on the edge they insert at.
   *
   * Before on the top edge, after on the bottom, derived from each op's own
   * position, not the spelling of its id. A knob carries its label, opened on
   * hover, and so no tooltip.
   */
  public function testAddOpsAreTheEdgeKnobs(): void {
    $outline = $this->chrome($this->inventory()->ops())['outline_ops'];

    $this->assertSame(['top', 'bottom'], array_keys($outline), 'Two clusters.');
    $ids = fn (string $edge) => array_map(fn (array $i) => $i['link']['#attributes']['data-op'], $outline[$edge]);
    $this->assertSame(['add-before'], $ids('top'), 'The top edge inserts before.');
    $this->assertSame(['add-after'], $ids('bottom'), 'The bottom edge inserts after.');
    foreach (['top', 'bottom'] as $edge) {
      foreach ($outline[$edge] as $item) {
        $this->assertSame('add', $item['verb']);
        $this->assertContains('neo-alchemist--op', $item['link']['#attributes']['class']);
        $this->assertFalse($item['link']['#title']->isIconOnly(), 'A knob carries its label.');
        $this->assertArrayNotHasKey('#tooltip', $item['link'], 'A knob needs no tooltip.');
      }
    }
  }

  /**
   * Every inventory op but Edit is a button, on the surfaces it belongs.
   *
   * The chrome is a pure projection of the inventory: the inserting ops on the
   * outline's edges and nowhere else, everything else on the rail and in the
   * menu. A hardcoded op, or one dropped by a broken loop, reddens this.
   */
  public function testEveryOpBecomesExactlyOneButtonPerSurface(): void {
    $chrome = $this->chrome($this->inventory()->ops());

    $outlineIds = [];
    foreach (['top', 'bottom'] as $edge) {
      foreach ($chrome['outline_ops'][$edge] as $item) {
        $outlineIds[] = $item['link']['#attributes']['data-op'];
      }
    }
    sort($outlineIds);
    $this->assertSame(['add-after', 'add-before'], $outlineIds, 'The edges carry the inserting ops, and only those.');

    $rail = array_keys($chrome['outline_rail']);
    sort($rail);
    $this->assertSame(['clone', 'delete', 'move-down', 'move-up'], $rail, 'The rail holds every other op but edit.');
    $this->assertSame(array_keys($chrome['outline_rail']), array_keys($chrome['inspector_menu']), 'The menu repeats the rail.');
  }

  /**
   * Adding an inventory row adds a button — no preprocess or template edit.
   *
   * This is the whole point of the ticket, shown rather than assumed: a
   * synthetic verb op and a synthetic move op are appended to the real
   * inventory and handed to the same builder the preprocess calls. The verb
   * joins the end of the rail and the menu; the move joins the moves, ahead of
   * the rule — with no change to _neo_alchemist_overlay_ops() or the templates,
   * which loop over whatever the inventory contains.
   */
  public function testAddingAnInventoryRowAddsButton(): void {
    $ops = $this->inventory()->ops();
    $ops['duplicate'] = new EditorOp(
      id: 'duplicate',
      verb: 'duplicate',
      position: NULL,
      direction: NULL,
      rel: 'clone',
      label: 'Duplicate',
      short: NULL,
      icon: NULL,
    );
    $ops['move-top'] = new EditorOp(
      id: 'move-top',
      verb: 'move',
      position: NULL,
      direction: 'up',
      rel: 'move',
      label: 'Move To Top',
      short: 'Top',
      icon: 'chevrons-up',
    );

    $chrome = $this->chrome($ops);

    $order = ['move-up', 'move-down', 'move-top', 'clone', 'delete', 'duplicate'];
    $this->assertSame($order, array_keys($chrome['outline_rail']), 'A move joins the moves, a verb the verbs.');
    $this->assertSame($order, array_keys($chrome['inspector_menu']), 'The menu follows.');
    $this->assertTrue($chrome['outline_rail']['clone']['divider'], 'The rule still comes before the first verb.');
    $this->assertFalse($chrome['outline_rail']['move-top']['divider']);
    $this->assertArrayNotHasKey('data-op-keys', $chrome['outline_rail']['duplicate']['link']['#attributes'], 'No keys, no shortcut.');
  }

}
