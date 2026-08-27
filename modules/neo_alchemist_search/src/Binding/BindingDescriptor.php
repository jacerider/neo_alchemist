<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Binding;

/**
 * One entity field a layout declares it puts on the page.
 *
 * Derived from component configuration, never from a rendered page. This is
 * what lets a locked layout contribute searchable text: the component says
 * which field a prop shows, so indexing can read that field off the entity.
 */
final class BindingDescriptor {

  /**
   * Constructs a BindingDescriptor.
   *
   * @param string $fieldKey
   *   A field key in the matcher grammar: dot-separated hops, each
   *   `fieldName[:property]`.
   * @param int $hops
   *   How many reference hops the key crosses. Zero is the host entity's own
   *   field.
   * @param string $componentId
   *   The component that declared the binding, for reporting.
   * @param string $shapeId
   *   The shape it feeds, for reporting.
   * @param string $pluginId
   *   The value plugin that declared it, for reporting.
   */
  public function __construct(
    public readonly string $fieldKey,
    public readonly int $hops,
    public readonly string $componentId,
    public readonly string $shapeId,
    public readonly string $pluginId,
  ) {}

  /**
   * A key identifying the field this reads, ignoring which prop wanted it.
   *
   * Several components on one page routinely surface the same field; reading it
   * once is both cheaper and keeps the collected text honest.
   */
  public function dedupeKey(): string {
    return $this->fieldKey;
  }

}
