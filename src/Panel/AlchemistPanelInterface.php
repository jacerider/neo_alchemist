<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Panel;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * A side panel another module adds to the Alchemist layout editor.
 *
 * The panel gets a toggle beside Layers, a floating box to mount into, and one
 * key in the shared layout draft. What it writes there is saved, reverted and
 * reset with the layout, and handed back to publish() when the layout goes
 * live. Entity scope only: a field default layout and a block host have no
 * per-entity draft.
 *
 * The client half registers with Drupal.neoAlchemist.panels.register(), from a
 * library build() attaches. See ARCHITECTURE.md "Editor side panels".
 */
interface AlchemistPanelInterface extends PluginInspectionInterface {

  /**
   * The panel's name, shown on its toggle and in its header.
   */
  public function label(): string;

  /**
   * Whether the account may see and write this panel for the field item.
   *
   * Checked when the editor is built, on every read and write of the panel's
   * draft data, and again on publish. The route already requires update access
   * to the host entity; this narrows it further.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The entity-scope field item being edited.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(ComponentTreeItem $item, AccountInterface $account): AccessResultInterface;

  /**
   * The panel body: a mount element plus the libraries and settings it needs.
   *
   * Rendered once inside the hidden panel box; the client's mount() receives
   * that box's body element on first open.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The entity-scope field item being edited.
   *
   * @return array
   *   A render array.
   */
  public function build(ComponentTreeItem $item): array;

  /**
   * The panel's badge in the editor's top bar, or an empty array for none.
   *
   * Placed left of the scale controls, in panel weight order, only while
   * access() passes. The top bar is never re-rendered, so the badge's client
   * half (the `badge()` of its registration) keeps it current: it runs at
   * editor load, gets the same draft api as mount(), and a dropdown that the
   * editor anchors under the badge. Every <button> in the badge toggles it.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The entity-scope field item being edited.
   *
   * @return array
   *   A render array; empty for no badge.
   */
  public function badge(ComponentTreeItem $item): array;

  /**
   * Applies the panel's drafted data to the entity just before it is saved.
   *
   * Called on Publish only, after the layout value has been synced onto the
   * entity and before $entity->save(). Do not save the entity here. Revert and
   * Reset discard the data without calling this.
   *
   * The data came from the browser: validate it before applying it.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being published.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity about to be saved.
   * @param mixed $data
   *   What the panel last wrote to its draft key (never NULL).
   */
  public function publish(ComponentTreeItem $item, ContentEntityInterface $entity, mixed $data): void;

  /**
   * Whether the panel's drafted data is an edit, not values it computed.
   *
   * Data the panel works out for itself (a score) is no change of the
   * editor's: undo that brings the layout back to the saved one deletes the
   * draft when no panel holds an edit, so the editor goes quiet.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   * @param mixed $data
   *   What the panel last wrote to its draft key.
   *
   * @return bool
   *   TRUE when the data holds something the editor changed.
   *
   * @see \Drupal\neo_alchemist\EditorState\EditorHistory
   */
  public function isEdited(ComponentTreeItem $item, mixed $data): bool;

}
