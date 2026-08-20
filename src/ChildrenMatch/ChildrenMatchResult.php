<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * What a children-match source resolved.
 *
 * Three outcomes, because the producers genuinely have three and used to
 * express them by hand-written guards around the mapping call:
 *
 * - unavailable(): nothing is configured, the view did not execute, the filter
 *   is gone. The provider contributes nothing and the threaded value stands.
 * - of(): these entities. An empty list still maps, which for a non-iterable
 *   shape produces the per-child empty map that hides an unbound child.
 * - emptyValue(): the source ran and found nothing, and nothing must resolve to
 *   an empty value rather than that per-child map — otherwise
 *   isProvidedValueEmpty() reads the map as NON-empty, a stop_when_found
 *   provider claims it, and a fallback provider is starved while every child is
 *   force-hidden.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface::getChildrenMatchEntities()
 */
final class ChildrenMatchResult {

  /**
   * Constructs a ChildrenMatchResult.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[]|null $entities
   *   The entities to iterate, or NULL when the source could not run.
   * @param bool $mapsWhenEmpty
   *   Whether an empty list is still handed to the mapper.
   */
  private function __construct(
    public readonly ?array $entities,
    public readonly bool $mapsWhenEmpty,
  ) {}

  /**
   * The source could not run at all.
   */
  public static function unavailable(): self {
    return new self(NULL, FALSE);
  }

  /**
   * The source ran and resolved these entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $entities
   *   The entities to iterate, in order.
   */
  public static function of(array $entities): self {
    return new self(array_values(array_filter(
      $entities,
      static fn ($entity) => $entity instanceof ContentEntityInterface,
    )), TRUE);
  }

  /**
   * The source ran, found nothing, and empty must resolve to an empty value.
   */
  public static function emptyValue(): self {
    return new self([], FALSE);
  }

}
