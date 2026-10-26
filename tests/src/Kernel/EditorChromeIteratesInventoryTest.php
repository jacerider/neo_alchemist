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
 * templates loop over — the two outline edge clusters (`outline_ops`), and the
 * inspector header's buttons (`inspector_ops`) and ⋯ menu (`inspector_menu`).
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
   *   The `outline_ops`, `inspector_ops` and `inspector_menu` render
   *   structures.
   */
  private function chrome(array $ops): array {
    return _neo_alchemist_overlay_ops($ops);
  }

  /**
   * The verb ops but Edit project to the inspector's ⋯ menu, in order.
   *
   * A verb op acts on the component itself; it carries neither a position nor
   * a direction. Edit is the inspector itself, so it has no item. Each item is
   * labelled, keyed by its op id, and appears only in the menu — never among
   * the inspector's buttons or on the outline. Delete destroys, so it takes the
   * alert colour.
   */
  public function testVerbOpsProjectToTheInspectorMenu(): void {
    $chrome = $this->chrome($this->inventory()->ops());

    $this->assertSame(
      ['sort', 'clone', 'delete'],
      array_keys($chrome['inspector_menu']),
      'The verb ops but edit are the ⋯ menu, in inventory order.',
    );
    $labels = ['sort' => 'Sort', 'clone' => 'Clone', 'delete' => 'Delete'];
    foreach ($labels as $id => $label) {
      $item = $chrome['inspector_menu'][$id];
      $this->assertSame('link', $item['#type'], "$id is a link");
      $this->assertSame($id, $item['#attributes']['data-op'], "$id data-op");
      $classes = $item['#attributes']['class'];
      $this->assertContains('neo-alchemist--op', $classes, "$id is an op");
      $this->assertSame($id === 'delete', in_array('text-alert-700', $classes, TRUE), "$id alert colour only on delete");
      $this->assertInstanceOf(IconElement::class, $item['#title'], "$id icon");
      $this->assertFalse($item['#title']->isIconOnly(), "$id is labelled");
      $this->assertSame($label, (string) $item['#title']->getText(), "$id label");
    }
    $this->assertArrayNotHasKey('edit', $chrome['inspector_ops']);
  }

  /**
   * The positional ops split onto the two outline edge clusters by their data.
   *
   * The before/up ops hang off the top edge, after/down off the bottom —
   * derived from each op's own position/direction, not the spelling of its id.
   * Within a
   * cluster the add op precedes the move op, as the outline renders them today,
   * and each outline button is icon-only with its tooltip on its own edge.
   */
  public function testPositionalOpsSplitIntoTheTwoEdgeClusters(): void {
    $outline = $this->chrome($this->inventory()->ops())['outline_ops'];

    $this->assertSame(['top', 'bottom'], array_keys($outline), 'Two clusters.');
    $this->assertSame(
      ['add-before', 'move-up'],
      array_map(fn (array $i) => $i['link']['#attributes']['data-op'], $outline['top']),
      'The top edge carries add-before then move-up.',
    );
    $this->assertSame(
      ['add-after', 'move-down'],
      array_map(fn (array $i) => $i['link']['#attributes']['data-op'], $outline['bottom']),
      'The bottom edge carries add-after then move-down.',
    );
    // The verb travels with the op so the template can pick the add/move colour
    // ramp without naming an op.
    $this->assertSame('add', $outline['top'][0]['verb']);
    $this->assertSame('move', $outline['top'][1]['verb']);
    foreach (['top', 'bottom'] as $edge) {
      foreach ($outline[$edge] as $item) {
        $button = $item['link'];
        $classes = $button['#attributes']['class'];
        $this->assertContains('neo-alchemist--op', $classes);
        $this->assertTrue($button['#title']->isIconOnly(), 'Outline icon only.');
        $placement = $button['#tooltip_options']['placement'];
        $this->assertSame($edge, $placement, 'Tooltip on the op edge.');
      }
    }
  }

  /**
   * The move ops are the inspector header's buttons, named for screen readers.
   *
   * The outline's copies sit off screen whenever the component is taller than
   * the canvas, so the header keeps them in reach. Only ops with a direction
   * are there: adding is left to the outline and the + seams, which say where.
   */
  public function testMoveOpsAreTheInspectorButtons(): void {
    $buttons = $this->chrome($this->inventory()->ops())['inspector_ops'];

    $this->assertSame(['move-up', 'move-down'], array_keys($buttons), 'The move ops, in inventory order.');
    foreach (['move-up' => 'Move component up', 'move-down' => 'Move component down'] as $id => $label) {
      $button = $buttons[$id];
      $this->assertSame($id, $button['#attributes']['data-op'], "$id data-op");
      $this->assertContains('neo-alchemist--op', $button['#attributes']['class'], "$id is an op");
      $this->assertInstanceOf(IconElement::class, $button['#title']);
      $this->assertTrue($button['#title']->isIconOnly(), "$id shows only its icon");
      $this->assertSame($label, (string) $button['#title']->getText(), "$id keeps its name for screen readers");
    }
  }

  /**
   * Every inventory op becomes exactly one button, on the surfaces it belongs.
   *
   * The chrome is a pure projection of the inventory: every op but edit is on
   * the outline or in the inspector (the move ops on both), and the outline
   * holds the four positional ops and no others. A hardcoded op, or one
   * dropped by a broken loop, reddens this.
   */
  public function testEveryOpBecomesExactlyOneButton(): void {
    $chrome = $this->chrome($this->inventory()->ops());

    $inspector = array_merge(
      array_keys($chrome['inspector_menu']),
      array_keys($chrome['inspector_ops']),
    );
    sort($inspector);
    $this->assertSame(['clone', 'delete', 'move-down', 'move-up', 'sort'], $inspector, 'The inspector holds every verb op but edit, and the move ops.');

    $outlineIds = [];
    foreach (['top', 'bottom'] as $edge) {
      foreach ($chrome['outline_ops'][$edge] as $item) {
        $outlineIds[] = $item['link']['#attributes']['data-op'];
      }
    }
    sort($outlineIds);
    $this->assertSame(
      ['add-after', 'add-before', 'move-down', 'move-up'],
      $outlineIds,
      'The outline carries the four positional ops, and only those.',
    );
  }

  /**
   * Adding an inventory row adds a button — no preprocess or template edit.
   *
   * This is the whole point of the ticket, shown rather than assumed: a
   * synthetic verb op and a synthetic positional op are appended to the real
   * inventory and handed to the same builder the preprocess calls. The verb op
   * becomes an item in the inspector's ⋯ menu; the positional op becomes an
   * inspector button and sits on the outline edge its direction names — with
   * no change to _neo_alchemist_overlay_ops() or the templates, which loop over
   * whatever the inventory contains.
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

    // The new verb op is an item in the inspector's ⋯ menu.
    $this->assertArrayHasKey('duplicate', $chrome['inspector_menu']);
    $newVerb = $chrome['inspector_menu']['duplicate']['#attributes']['data-op'];
    $this->assertSame('duplicate', $newVerb);
    $this->assertArrayNotHasKey('duplicate', $chrome['inspector_ops']);

    // The new positional op (direction up) joins the inspector's buttons and
    // the top outline edge.
    $this->assertArrayHasKey('move-top', $chrome['inspector_ops']);
    $topEdge = array_map(
      fn (array $i) => $i['link']['#attributes']['data-op'],
      $chrome['outline_ops']['top'],
    );
    $this->assertContains('move-top', $topEdge, 'A direction-up op is on top.');
  }

}
