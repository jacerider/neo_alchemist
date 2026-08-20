<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;

/**
 * Runs the provide phase of a prop's value pipeline.
 *
 * The provider search is the one pass where "which plugin's value wins" is a
 * decision the site builder gets to make. It threads a seed through the ordered
 * providers, asks each producer for its outcome, lets each configured
 * processing mode decide whether an unclaimed value claims (halting the search)
 * or falls through, keeps an empty non-claiming result from destroying the
 * value it was handed, and stops at the first claim.
 *
 * It owns that decision and nothing else: it is handed the already-ordered
 * instances and the seed, and returns the value the phase settled on.
 * Everything around the phase — resolving the seed from the schema example, a
 * field default override, the modifier pass, the required-but-empty fallback —
 * stays with the shape that calls this. Holding no state and reaching for
 * nothing but each producer's outcome and the shape's emptiness contract is
 * what makes the phase testable against a handful of producers with no
 * container.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::computeDefaultValue()
 */
final class ValueProviderSearch {

  /**
   * Threads a seed through the ordered providers and returns the result.
   *
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[] $instances
   *   The ordered producers to consult. Each returns an outcome; none holds
   *   claim state, so the list needs no resetting between passes.
   * @param mixed $seed
   *   The value the search starts from — the shape's resolved schema example.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapeValueInterface $shape
   *   The shape that owns the value, consulted only for its emptiness contract:
   *   a composite discounts whichever keys it reports as presentational.
   *
   * @return mixed
   *   The value the provide phase settled on.
   */
  public function search(array $instances, mixed $seed, ComponentShapeValueInterface $shape): mixed {
    $value = $seed;
    foreach ($instances as $instance) {
      $provision = $instance->provide($value);
      $provided = $provision->getValue();
      // A producer either claims its value itself (a veto) or leaves the claim
      // to the site builder's configured processing mode. Only ask the mode
      // when the producer has not already claimed — a self-raised claim (e.g. a
      // subscriber veto through the event provider) outranks the mode.
      $claimed = $provision->isClaimed();
      if (!$claimed && $instance instanceof ComponentValueProcessingModeInterface) {
        $claimed = $instance->claimsValue($provided);
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
      $value = $shape->isProvidedValueEmpty($provided) && !$claimed
        ? $value
        : $provided;
      if ($claimed) {
        break;
      }
    }
    return $value;
  }

}
