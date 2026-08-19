<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * What a tree operation does with a section or slot it has just emptied.
 *
 * "A section that has become empty" means two incompatible things depending on
 * which subsystem is asking, and both readings are individually correct:
 *
 * - To config-scope dependency removal it means *nothing is left here* — the
 *   structure validator rejects empty slots and empty subtrees outright, so a
 *   default layout that keeps them cannot be saved.
 * - To hybrid entity storage it means *explicitly emptied* — a creator cleared
 *   a flagged region and that decision is authoritative. Collapsing it turns
 *   the stored subset back into "nothing stored", which the next load reads as
 *   "reset to the field default" and silently repopulates with the site
 *   builder's seed components.
 *
 * The divergence is invisible until the two subsystems meet — detaching a
 * deleted component from a hybrid entity is exactly that meeting — so the
 * choice is a named argument at every call site rather than an emergent
 * property of whichever code path happens to be running.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::detachComponents()
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::removeComponent()
 */
enum EmptySectionPolicy {

  // Drop a slot left with no instances, and a section left with no slot: the
  // shape ComponentTreeStructureConstraintValidator demands of a config-scope
  // tree, which rejects both an empty slot and an empty subtree.
  case Collapse;

  // Keep an emptied slot as an empty list, and keep its section: the shape
  // hybrid entity storage demands, where an empty flagged slot is the marker
  // that says "this region was deliberately emptied" and the merge on load
  // distinguishes it from an absent one.
  case Preserve;

}
