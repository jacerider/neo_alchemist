<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\ChildrenMatch;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;

/**
 * A source whose one pseudo field recurses onto a second list of entities.
 *
 * This stands in for `_reference` and `_expand` at the unit level: the shape a
 * `_recurse` field points at is itself filled from a NEW list of entities, one
 * level deeper in the shape id chain, exactly as a followed reference or an
 * expanded child is. What matters for the published policy is that this nested
 * list is a different set of entities from the one the source returned — the
 * level the trait used to leave unfiltered.
 *
 * The handler reads the published decision off the field it was handed and
 * threads it into the recursion, which is the whole contract ticket 11 pins:
 * the flag is resolved once and passed down, never re-derived from a child
 * settings array that cannot carry it.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField::$published
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper::fetchValues()
 */
final class FakeRecursingChildrenMatchSource implements ChildrenMatchFieldSourceInterface {

  /**
   * Constructs a FakeRecursingChildrenMatchSource.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult $result
   *   The top-level entities.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $nested
   *   The entities the `_recurse` field walks on to — the level the published
   *   flag has to reach.
   */
  public function __construct(
    private readonly ChildrenMatchResult $result,
    private readonly array $nested,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    return $this->result;
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchHandlers(): array {
    return [new FakeRecursingChildrenMatchHandler($this->nested)];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return 'fake_recursing_source';
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition(): array {
    return ['id' => 'fake_recursing_source'];
  }

}
