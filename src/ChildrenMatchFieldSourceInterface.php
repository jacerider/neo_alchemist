<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * A source that contributes pseudo-field handlers of its own to the mapping.
 *
 * Most sources yield entities and stop — every choice a child shape can be
 * bound to is then either a field on those entities or one of the mapper's own
 * pseudo fields. A views source is the exception: a view can render a column
 * that is not a field on the row's entity at all, so it both offers `_view:*`
 * choices and knows how to read them back.
 *
 * It contributes them as handlers registered into the mapper's own handler map,
 * so there is one abstraction for every pseudo field — built-in or plugin — and
 * a source's choice cannot drift between the option, the form and the fetch any
 * more than a built-in's can. The mapper's own handlers take precedence, so a
 * source cannot shadow `_default` or `_reference`.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchMapper
 * @see \Drupal\neo_alchemist\ChildrenMatchHandlerInterface
 */
interface ChildrenMatchFieldSourceInterface extends ChildrenMatchSourceInterface {

  /**
   * The pseudo-field handlers this source contributes to the mapping.
   *
   * Each is registered by its getName(), which is both the map key and the
   * prefix it claims: a `_view:title` key reaches a handler named `view`, while
   * `_entity:label` — named `entity` and claimed by nobody — falls through to
   * the field matcher, which is why a views mapping can lean on `_entity:*` on
   * rows of any entity type.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatchHandlerInterface[]
   *   The handlers, in the order their options should be offered.
   */
  public function getChildrenMatchHandlers(): array;

}
