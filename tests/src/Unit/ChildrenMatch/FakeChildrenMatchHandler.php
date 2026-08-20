<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\ChildrenMatch;

use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchField;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchFormContext;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerBase;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;

/**
 * A source-contributed handler standing in for the views `_view:` one.
 *
 * It records that the mapper asked it for a value, so a test can assert a
 * source's own field choices are reached through the handler registry — and
 * only when the stored key actually names the handler, never for a
 * field-matcher key like `_entity:label`.
 *
 * @see \Drupal\Tests\neo_alchemist\Unit\ChildrenMatch\FakeChildrenMatchSource
 */
final class FakeChildrenMatchHandler extends ChildrenMatchHandlerBase {

  /**
   * Constructs a FakeChildrenMatchHandler.
   *
   * @param \Drupal\Tests\neo_alchemist\Unit\ChildrenMatch\FakeChildrenMatchSource $source
   *   The source to record asks on.
   * @param string $prefix
   *   The name this handler answers to.
   * @param mixed $value
   *   What fetch() returns.
   */
  public function __construct(
    private readonly FakeChildrenMatchSource $source,
    private readonly string $prefix,
    private readonly mixed $value,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return $this->prefix;
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    $options['- Fake -']['_' . $this->prefix . ':column'] = 'Fake ' . $this->prefix;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    $this->source->askedFor[] = $this->prefix;
    return $this->value;
  }

}
