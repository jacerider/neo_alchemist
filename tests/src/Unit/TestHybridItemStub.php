<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

/**
 * A minimal stand-in for a ComponentTreeItem in pure-array unit tests.
 *
 * The extract path only calls getValue() on the first item, so this named
 * stub (no anonymous classes — phpcbf mangles them) is all the item surface
 * the algebra tests need.
 */
final class TestHybridItemStub {

  public function __construct(
    private readonly array $value,
  ) {}

  /**
   * Returns the stubbed item value.
   *
   * @return array
   *   The item value with 'tree'/'props' keys.
   */
  public function getValue(): array {
    return $this->value;
  }

}
