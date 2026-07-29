<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\ComponentShape\StringShape;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The value-emptiness contract, pinned case by case.
 *
 * The predicate is the shared "found vs empty" test the whole
 * value pipeline leans on — the claim modes, the array/object branch tables
 * and the child distribution all changed to route through it. Its two rules:
 * a scalar is empty only when NULL or ''; an array is empty only when it has
 * nothing left after discounting the seeded `size` key.
 *
 * The method touches no instance state, so the shape is built without its
 * constructor.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::isProvidedValueEmpty()
 */
#[Group('neo_alchemist')]
class IsProvidedValueEmptyTest extends UnitTestCase {

  /**
   * The contract table.
   *
   * @return array
   *   Cases of [value, expected emptiness].
   */
  public static function contractCases(): array {
    return [
      'NULL is empty' => [NULL, TRUE],
      'empty string is empty' => ['', TRUE],
      'zero int is a value' => [0, FALSE],
      'zero float is a value' => [0.0, FALSE],
      'zero string is a value' => ['0', FALSE],
      'FALSE is a value' => [FALSE, FALSE],
      'whitespace is a value' => [' ', FALSE],
      'plain string is a value' => ['x', FALSE],
      'empty array is empty' => [[], TRUE],
      'array with a falsy member is a value' => [[0], FALSE],
      'size-only array is empty (seeded sentinel)' => [['size' => 'lg'], TRUE],
      'size plus content is a value' => [['size' => 'lg', 'src' => 'a.png'], FALSE],
    ];
  }

  /**
   * Each case resolves per the contract.
   */
  #[DataProvider('contractCases')]
  public function testContract(mixed $value, bool $expected): void {
    $reflection = new \ReflectionClass(StringShape::class);
    /** @var \Drupal\neo_alchemist\Plugin\ComponentShape\StringShape $shape */
    $shape = $reflection->newInstanceWithoutConstructor();

    $this->assertSame($expected, $shape->isProvidedValueEmpty($value));
  }

}
