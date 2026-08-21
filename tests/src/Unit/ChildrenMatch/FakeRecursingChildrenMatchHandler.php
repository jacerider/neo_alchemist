<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\ChildrenMatch;

use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerBase;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;

/**
 * The `_recurse` handler: fills a nested list, threading the published flag.
 *
 * The unit-level stand-in for `_expand` and `_reference`. Both walk on to a
 * further set of entities and recurse through
 * \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper::fetchValues(); the
 * one thing they must get right for ticket 11 is that they pass down the
 * published decision they were handed rather than let the nested level
 * re-derive it from a child settings array.
 *
 * @see \Drupal\Tests\neo_alchemist\Unit\ChildrenMatch\FakeRecursingChildrenMatchSource
 */
final class FakeRecursingChildrenMatchHandler extends ChildrenMatchHandlerBase {

  /**
   * Constructs a FakeRecursingChildrenMatchHandler.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $nested
   *   The entities the nested level maps.
   */
  public function __construct(
    private readonly array $nested,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'recurse';
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    // A followed reference maps a different set of entities against the child's
    // own children. The published decision is the one the parent was handed —
    // $field->published — not anything read back out of $field->settings, which
    // never carries shape_published.
    return $mapper->fetchValues(
      $source,
      ['label'],
      $field->shape,
      $this->nested,
      $field->published,
      ['shape_fields' => ['label' => ['field' => '_entity:label']]],
      $field->shapeId,
      TRUE,
    );
  }

}
