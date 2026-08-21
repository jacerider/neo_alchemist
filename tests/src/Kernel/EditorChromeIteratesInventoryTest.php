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
 * the seam that projects the op vocabulary into the three render surfaces the
 * template loops over — the bottom bar's verb group (`ops`), its positional
 * group (`position_ops`), and the two outline edge clusters (`outline_ops`).
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
   *   The `ops`, `position_ops` and `outline_ops` render structures.
   */
  private function chrome(array $ops): array {
    return _neo_alchemist_overlay_ops($ops);
  }

  /**
   * The four verb ops project to the bottom bar's left group, in order.
   *
   * A verb op acts on the component itself; it carries neither a position nor a
   * direction. Each is a primary button keyed by its op id and appears only in
   * this group — never among the positional ops or on the outline.
   */
  public function testVerbOpsProjectToTheBottomBarLeftGroup(): void {
    $chrome = $this->chrome($this->inventory()->ops());

    $this->assertSame(
      ['edit', 'sort', 'clone', 'delete'],
      array_keys($chrome['ops']),
      'The verb ops are the bottom bar left group, in inventory order.',
    );
    $labels = ['edit' => 'Edit', 'sort' => 'Sort', 'clone' => 'Clone', 'delete' => 'Delete'];
    foreach ($labels as $id => $label) {
      $button = $chrome['ops'][$id];
      $this->assertSame('link', $button['#type'], "$id is a link");
      $this->assertSame($id, $button['#attributes']['data-op'], "$id data-op");
      $classes = $button['#attributes']['class'];
      $this->assertContains('btn-primary', $classes, "$id is a primary button");
      $this->assertContains('neo-alchemist--op', $classes, "$id is an op");
      $placement = $button['#tooltip_options']['placement'];
      $this->assertSame('top', $placement, "$id tooltip on top");
      $this->assertInstanceOf(IconElement::class, $button['#title'], "$id icon");
      $this->assertTrue($button['#title']->isIconOnly(), "$id icon only");
      $this->assertSame($label, (string) $button['#title']->getText(), "$id label");
    }
    $this->assertArrayNotHasKey('edit', $chrome['position_ops']);
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
   * The positional ops also appear, labelled, in the bottom bar's right group.
   *
   * The outline copies sit off screen whenever the component is taller than the
   * viewport, so the fixed bar is the always-visible route to them. There they
   * are secondary buttons and labelled — the add ops with their short label,
   * because the bar has no edge context to say which plus is before and which
   * is after; the move ops carry only their icon.
   */
  public function testPositionalOpsAppearLabelledInTheBottomBar(): void {
    $positionOps = $this->chrome($this->inventory()->ops())['position_ops'];

    $this->assertSame(
      ['add-before', 'add-after', 'move-up', 'move-down'],
      array_keys($positionOps),
      'The positional ops are the right group, in inventory order.',
    );
    foreach ($positionOps as $id => $button) {
      $this->assertSame($id, $button['#attributes']['data-op'], "$id data-op");
      $classes = $button['#attributes']['class'];
      $this->assertContains('btn-secondary', $classes, "$id is secondary");
      $this->assertInstanceOf(IconElement::class, $button['#title']);
      $this->assertFalse($button['#title']->isIconOnly(), "$id is labelled");
    }
    $before = (string) $positionOps['add-before']['#title']->getText();
    $after = (string) $positionOps['add-after']['#title']->getText();
    $move = (string) $positionOps['move-up']['#title']->getText();
    $this->assertSame('Before', $before);
    $this->assertSame('After', $after);
    $this->assertSame('', $move, 'Move ops carry no label.');
  }

  /**
   * Every inventory op becomes exactly one button, on the surfaces it belongs.
   *
   * The chrome is a pure projection of the inventory: the union of the two
   * bottom-bar groups is exactly the op set, verb ops appear only in the left
   * group and positional ops only in the right, and the outline holds the four
   * positional ops and no others. A hardcoded op, or one dropped by a broken
   * loop, reddens this.
   */
  public function testEveryOpBecomesExactlyOneButton(): void {
    $chrome = $this->chrome($this->inventory()->ops());

    $bar = array_merge(
      array_keys($chrome['ops']),
      array_keys($chrome['position_ops']),
    );
    sort($bar);
    $opIds = array_keys($this->inventory()->ops());
    sort($opIds);
    $this->assertSame($opIds, $bar, 'Every op is one bottom-bar button.');

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
   * becomes a button in the bottom bar's left group; the positional op becomes
   * a button in the right group and on the outline edge its direction names —
   * with no change to _neo_alchemist_overlay_ops() or the template, which loop
   * over whatever the inventory contains.
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

    // The new verb op is a bottom-bar button in the left group.
    $this->assertArrayHasKey('duplicate', $chrome['ops']);
    $newVerb = $chrome['ops']['duplicate']['#attributes']['data-op'];
    $this->assertSame('duplicate', $newVerb);
    $this->assertArrayNotHasKey('duplicate', $chrome['position_ops']);

    // The new positional op (direction up) joins the right group and the top
    // outline edge, labelled with its short label there.
    $this->assertArrayHasKey('move-top', $chrome['position_ops']);
    $short = (string) $chrome['position_ops']['move-top']['#title']->getText();
    $this->assertSame('Top', $short);
    $topEdge = array_map(
      fn (array $i) => $i['link']['#attributes']['data-op'],
      $chrome['outline_ops']['top'],
    );
    $this->assertContains('move-top', $topEdge, 'A direction-up op is on top.');
  }

}
