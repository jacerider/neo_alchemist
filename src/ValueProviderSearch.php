<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Runs the provide phase of a prop's value pipeline.
 *
 * The provider search is the one pass where "which plugin's value wins" is a
 * decision the site builder gets to make. It threads a seed through the ordered
 * providers, lets each configured processing mode decide whether its provider
 * claims the value (halting the search) or falls through, keeps an empty
 * non-claiming result from destroying the value it was handed, and stops at the
 * first claim.
 *
 * It owns that decision and nothing else: it is handed the already-ordered,
 * already-reset instances and the seed, and returns the value the phase settled
 * on. Everything around the phase — resolving the seed from the schema example,
 * a field default override, the modifier pass, the required-but-empty fallback
 * — stays with the shape that calls this. Holding no state and reaching for
 * nothing but the shape's emptiness contract is what makes the phase testable
 * against a handful of providers with no container.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::computeDefaultValue()
 */
final class ValueProviderSearch {

  /**
   * Threads a seed through the ordered providers and returns the result.
   *
   * @param \Drupal\neo_alchemist\ComponentValuePluginInterface[] $instances
   *   The ordered providers to consult, already reset to "not yet claimed" by
   *   the collection that handed them over. The search reads their claim state
   *   but does not reset it.
   * @param mixed $seed
   *   The value the search starts from — the shape's resolved schema example.
   * @param \Drupal\neo_alchemist\ComponentShapeValueInterface $shape
   *   The shape that owns the value, consulted only for its emptiness contract:
   *   a composite discounts whichever keys it reports as presentational.
   *
   * @return mixed
   *   The value the provide phase settled on.
   */
  public function search(array $instances, mixed $seed, ComponentShapeValueInterface $shape): mixed {
    $value = $seed;
    foreach ($instances as $instance) {
      $provided = $instance->provideDefaultValue($value);
      // Let the configurable processing mode decide whether this provider
      // claims the value (halting the search) or falls through to the next.
      if ($instance instanceof ComponentValueProcessingModeInterface) {
        $instance->applyProcessingMode($provided);
      }
      // A producer that found nothing and did not claim contributes nothing:
      // the search moves on carrying the value that was threaded into it,
      // instead of that value being destroyed on the way past. "Stop when a
      // value is found" says what happens when one IS found; when one is not,
      // enabling the producer must not leave the prop worse off than never
      // having enabled it. Without this, attaching an Entity Field provider
      // to a prop whose entity field happens to be empty silently wiped the
      // schema example the pipeline had just seeded — so a component that
      // rendered its author's label ("Our Services") before the provider was
      // attached rendered nothing after, with no way to tell from the config
      // that a value had been thrown away.
      //
      // A claim is the deliberate empty: "Always stop (block if empty)" and
      // the vetoes (user_has_role, entity_has_value) claim precisely to say
      // that nothing IS the answer, and their emptiness is kept. That is the
      // mode to choose for a prop whose examples are editor scaffolding —
      // placeholder cards, images or menu links that must never reach a
      // visitor — rather than a usable default.
      $value = $shape->isProvidedValueEmpty($provided) && !$instance->hasClaimedValue()
        ? $value
        : $provided;
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    return $value;
  }

}
