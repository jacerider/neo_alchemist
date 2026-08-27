<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Authored;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;

/**
 * Decides which component instances on an entity are the entity's own.
 *
 * A component tree field renders in one of three modes, and only one of them
 * puts entity-specific text in the entity's row:
 *
 * - Free-form (`allow_custom: TRUE`) — the whole stored tree belongs to the
 *   entity. When no row was ever written the list is seeded with the field
 *   default layout instead, which `isDefault()` reports.
 * - Hybrid (a component in the default layout declares a `region_custom` prop)
 *   — the entity owns only the components it placed inside those regions, and
 *   their descendants. Everything else is inherited chrome.
 * - Locked — the field default layout is wholly authoritative and
 *   `NeoComponentTreeList::setValue()` discards whatever the row held. Nothing
 *   is entity-owned, even when a stale row exists.
 *
 * Extracting inherited components would give every entity of a bundle the same
 * text — on this suite that means the same handful of default-layout strings
 * repeated across hundreds of documents, which buries the real content. So the
 * gate is the load-bearing correctness property of the authored half, not an
 * optimisation.
 *
 * The gate reads the loaded field item rather than the raw database columns.
 * The raw hybrid columns are already the entity-owned subset, which is
 * tempting, but reading them would mean re-implementing default-revision and
 * translation selection, would index the stale rows that locked fields discard
 * on load, and would cost a query per entity at index time. The merge has
 * already happened by the time anything holds a field item, so the in-memory
 * route is both cheaper and more correct.
 */
final class OwnershipGate {

  /**
   * Opens the gate for one component tree field on one entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity, in the language the caller wants indexed.
   * @param string $fieldName
   *   The name of a `neo_component_tree` field on that entity.
   *
   * @return array{0: \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|null, 1: array<string, true>|null}
   *   The field item to extract from, or NULL when nothing on this field
   *   belongs to the entity; and the set of entity-owned instance UUIDs, or
   *   NULL to mean "every instance in the tree qualifies".
   */
  public function open(ContentEntityInterface $entity, string $fieldName): array {
    if (!$entity->hasField($fieldName)) {
      return [NULL, NULL];
    }
    $list = $entity->get($fieldName);
    if (!$list instanceof NeoComponentTreeList) {
      return [NULL, NULL];
    }

    // Order matters. NeoComponentTreeList::setValue() clears its isDefault flag
    // before the early return that discards a locked row, so a locked field
    // holding a stale row reports isDefault() === FALSE while actually serving
    // the default layout. Asking about locked first is what keeps that row out.
    if ($list->isLockedScope()) {
      return [NULL, NULL];
    }
    // Seeded default layout: the entity never wrote a row, so the tree on offer
    // is the bundle's shared one.
    if ($list->isDefault()) {
      return [NULL, NULL];
    }

    $item = $list->first();
    if (!$item instanceof ComponentTreeItem) {
      return [NULL, NULL];
    }

    if (!$list->isHybridScope()) {
      // Free-form with a stored row: the entity owns the whole tree.
      return [$item, NULL];
    }

    $owned = $item->getEntityOwnedUuids();
    if ($owned === []) {
      // Hybrid, but the custom regions are empty — every instance present is
      // inherited from the default layout.
      return [NULL, NULL];
    }
    return [$item, array_fill_keys($owned, TRUE)];
  }

}
