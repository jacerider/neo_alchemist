<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * One pseudo field of a children-match mapping.
 *
 * A pseudo field is a choice a child shape can be bound to that is not a field
 * on the iterated entity: "Use Default", "Expand", a reference to follow, a raw
 * literal. It used to be three things spread across three unlinked sites that
 * had to agree by hand — an entry in an options array, a `case` in a form
 * switch, and a fetch reached through the name — so a fourth site could add one
 * and only find out at render time that the three had drifted.
 *
 * A handler is those three things as one object: it declares what it offers for
 * a given shape (addOptions), the form that configures it (buildForm) and how
 * it reads a value back (fetch). The option, the branch and the fetch cannot
 * drift because they are the same class. The mapper keeps its handlers in a map
 * keyed by getName(); nothing can name a handler that is not registered.
 *
 * Most handlers are the mapper's own and constructed by it. A source may
 * contribute its own — the `views` provider adds `_view:` this way — through
 * \Drupal\neo_alchemist\ChildrenMatchFieldSourceInterface, which registers them
 * into the same map.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchMapper
 * @see \Drupal\neo_alchemist\ChildrenMatchHandlerBase
 * @see \Drupal\neo_alchemist\ChildrenMatchFieldSourceInterface
 */
interface ChildrenMatchHandlerInterface {

  /**
   * The underscore-stripped name this handler answers to.
   *
   * A stored field key of `_<name>`, `_<name>:suffix` or `_<name>~key` resolves
   * to this handler — `default` for `_default`, `raw` for `_raw:string`, and
   * `reference` for `_reference~ref`, `view` for `_view:title`. It is both
   * the map key and the prefix the handler claims: a key whose name matches no
   * registered handler falls through to the field matcher, which is what keeps
   * `_entity:label` a field-matcher key rather than a swallowed pseudo field.
   *
   * @return string
   *   The name.
   */
  public function getName(): string;

  /**
   * Adds this handler's option(s) to a child's field picker, when applicable.
   *
   * Called for every child shape, in the mapper's registration order, so the
   * grouped option array comes out in a stable order. A handler that does not
   * apply to the shape adds nothing.
   *
   * @param array $options
   *   The grouped options, by reference: `['- Group -' => ['_key' => label]]`.
   * @param \Drupal\neo_alchemist\ChildrenMatchFormContext $context
   *   The form-time context — the shape being configured, its scope and the
   *   form state.
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void;

  /**
   * Builds the configuration sub-form for a chosen field of this handler.
   *
   * @param array $form
   *   The child's form element, already carrying the field select.
   * @param array $configuration
   *   The stored settings for this child.
   * @param \Drupal\neo_alchemist\ChildrenMatchFormContext $context
   *   The form-time context, including the mapper for any recursion.
   *
   * @return array|null
   *   The modified form, or NULL to fall through to the mapper's inline
   *   value-plugin form — the branch a plain field match uses, and the one a
   *   handler with no configuration of its own (Use Default, This entity, a raw
   *   boolean) wants.
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array;

  /**
   * Reads this handler's value for one iterated entity.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatchField $field
   *   The child being filled for one entity — shape, entity, delta, chained
   *   shape id and stored settings.
   * @param \Drupal\neo_alchemist\ChildrenMatchMapper $mapper
   *   The mapper, for handlers that recurse (Expand, Reference) or resolve a
   *   child shape (This entity).
   * @param \Drupal\neo_alchemist\ChildrenMatchSourceInterface $source
   *   The producer, threaded into any recursion so nested levels keep the same
   *   source's own field choices.
   *
   * @return mixed
   *   The value, or NULL to contribute nothing to the child.
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed;

  /**
   * Whether a NULL fetch removes the child key rather than leaving it empty.
   *
   * Only "Use Default" says yes: it means this provider contributes nothing, so
   * the child's key is dropped and the SDC example survives. Every other
   * handler leaves the seeded empty array, so a child bound to a source that
   * resolved empty renders nothing rather than the component's placeholder.
   *
   * @return bool
   *   TRUE to unset the child key when fetch() returns NULL.
   */
  public function removeChildWhenAbsent(): bool;

}
