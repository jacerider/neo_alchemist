<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

/**
 * A producer's answer to the provide phase: a value, and whether it is claimed.
 *
 * A producer no longer mutates itself to say "I claim this value" — it returns
 * one of these, and the provider search interprets it. Two constructors cover
 * the three things a producer can mean:
 *
 * - **offer()** — "here is my value; let the site builder's processing mode
 *   decide its fate." The default. A producer that came up empty *abstains* by
 *   offering the value it was handed: the search's emptiness test then carries
 *   that threaded value past, so enabling an empty producer never leaves the
 *   prop worse off than not enabling it. (In `block` mode an offer still
 *   claims even when empty — the point of that mode is "empty means empty".)
 * - **claim()** — "this value is authoritative; halt the search and keep it
 *   even if empty." The vetoes (user_has_role, entity_has_value), a subscriber
 *   through the event provider, and the configured `default` fallback all say
 *   this. A claim outranks the processing mode, so the search does not
 *   re-decide it.
 *
 * Immutable and container-free: the object holds the decision, the instance
 * holds nothing, so the same producer run across two props cannot leak a claim
 * from the first into the second.
 *
 * @see \Drupal\neo_alchemist\ValueProviderSearch::search()
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::provide()
 */
final class ComponentValueProvision {

  /**
   * Constructs a ComponentValueProvision.
   *
   * @param mixed $value
   *   The value the producer produced this pass.
   * @param bool $claimed
   *   TRUE when the producer claims the value (halting the search), FALSE when
   *   it offers the value for the processing mode to decide.
   */
  private function __construct(
    private readonly mixed $value,
    private readonly bool $claimed,
  ) {}

  /**
   * A value offered for the processing mode to decide the fate of.
   *
   * @param mixed $value
   *   The produced value. A producer that could not act offers the value it was
   *   handed, which is how it abstains.
   *
   * @return self
   *   The provision.
   */
  public static function offer(mixed $value): self {
    return new self($value, FALSE);
  }

  /**
   * A value claimed authoritatively: halt the search, keep it even if empty.
   *
   * @param mixed $value
   *   The produced value, kept as the answer regardless of the processing mode.
   *
   * @return self
   *   The provision.
   */
  public static function claim(mixed $value): self {
    return new self($value, TRUE);
  }

  /**
   * The value the producer produced this pass.
   *
   * @return mixed
   *   The produced value.
   */
  public function getValue(): mixed {
    return $this->value;
  }

  /**
   * Whether the producer claimed the value.
   *
   * @return bool
   *   TRUE if the value is claimed (the search halts and keeps it even when
   *   empty), FALSE if the processing mode decides.
   */
  public function isClaimed(): bool {
    return $this->claimed;
  }

}
