<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\ChildrenMatch;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFieldSourceInterface;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;

/**
 * A children-match source that returns a fixed list of entities.
 *
 * The point of inverting the seam: every producer's mapping behaviour is now
 * reachable without executing a view or running an entity query, because the
 * only thing a producer contributes at render time is this list.
 *
 * Implements the optional field-source interface too, so one double covers
 * both the plain sources and the views-shaped one: it registers a
 * FakeChildrenMatchHandler per custom field, exactly as ViewsValue registers
 * its `_view:` handler.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface
 * @see \Drupal\Tests\neo_alchemist\Unit\ChildrenMatch\FakeChildrenMatchHandler
 */
final class FakeChildrenMatchSource implements ChildrenMatchFieldSourceInterface {

  /**
   * Prefixes this source was asked about, in order.
   *
   * @var string[]
   */
  public array $askedFor = [];

  /**
   * Constructs a FakeChildrenMatchSource.
   *
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult $result
   *   What getChildrenMatchEntities() should return.
   * @param array $customFields
   *   Values keyed by the prefix the mapper will ask about, e.g. ['view' => …].
   * @param \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope|null $scope
   *   What the source form should report, or NULL for "not configured".
   */
  public function __construct(
    private readonly ChildrenMatchResult $result,
    private readonly array $customFields = [],
    private readonly ?ChildrenMatchScope $scope = NULL,
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
    $form['fake_source'] = ['#type' => 'value', '#value' => 'built'];
    return $this->scope;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchHandlers(): array {
    $handlers = [];
    foreach ($this->customFields as $prefix => $value) {
      $handlers[] = new FakeChildrenMatchHandler($this, $prefix, $value);
    }
    return $handlers;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return 'fake_source';
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition(): array {
    return ['id' => 'fake_source'];
  }

}
