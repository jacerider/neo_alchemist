<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

/**
 * The entity type and bundle a children-match mapping binds against.
 *
 * A source resolves entities of one kind, and the mapping table offers the
 * fields of that kind. The scope is what the source knows and the mapper does
 * not: entity_query reads it from its own settings, views from the executed
 * view's base table, entity_reference from the field it follows.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface::buildChildrenMatchSourceForm()
 */
final class ChildrenMatchScope {

  /**
   * Constructs a ChildrenMatchScope.
   *
   * @param string $entityTypeId
   *   The entity type the iterated entities will have.
   * @param string|null $bundle
   *   The bundle, or NULL when the source can yield more than one. A NULL
   *   bundle widens the offered fields to every bundle of the entity type,
   *   which is the honest answer for a multi-bundle reference.
   */
  public function __construct(
    public readonly string $entityTypeId,
    public readonly ?string $bundle = NULL,
  ) {}

}
