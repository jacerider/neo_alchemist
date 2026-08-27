<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Binding;

/**
 * The bindings resolved for one component, plus the plugins that declared none.
 *
 * The second half is not diagnostics padding. A layout can legitimately
 * contribute no text at all, so "nothing was extracted" never looks alarming on
 * its own, and a value plugin that ought to name a field but doesn't would be
 * invisible. Carrying the silent plugins out of the resolver is what lets the
 * report command show the whole picture rather than only the half that worked.
 *
 * @see \Drupal\neo_alchemist_search\Drush\Commands\NeoAlchemistSearchCommands
 */
final class BindingSet {

  /**
   * Constructs a BindingSet.
   *
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor[] $descriptors
   *   The fields this component declares it surfaces.
   * @param array<string, int> $silent
   *   Value plugin ids that named no host-entity field, and how often. Most are
   *   correct — views results, menus, site-wide settings and literal defaults
   *   all read nothing about this entity. One that looks wrong is a plugin
   *   missing its field-source declaration.
   */
  public function __construct(
    public readonly array $descriptors = [],
    public readonly array $silent = [],
  ) {}

  /**
   * Merges another set into this one, de-duplicating descriptors.
   */
  public function merge(BindingSet $other): self {
    $descriptors = $this->descriptors;
    $seen = [];
    foreach ($descriptors as $descriptor) {
      $seen[$descriptor->dedupeKey()] = TRUE;
    }
    foreach ($other->descriptors as $descriptor) {
      $key = $descriptor->dedupeKey();
      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $descriptors[] = $descriptor;
      }
    }
    $silent = $this->silent;
    foreach ($other->silent as $id => $count) {
      $silent[$id] = ($silent[$id] ?? 0) + $count;
    }
    return new self($descriptors, $silent);
  }

}
