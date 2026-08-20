<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Shape;

use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\ImageShape;
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
 * nothing left after discounting the keys the shape calls presentational.
 *
 * The second rule is per-shape, so the table below runs on a shape that names
 * no presentational keys and the shapes that do get their own test.
 *
 * The method touches no instance state, so the shapes are built without their
 * constructors.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::isProvidedValueEmpty()
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getPresentationalValueKeys()
 */
#[Group('neo_alchemist')]
class IsProvidedValueEmptyTest extends UnitTestCase {

  /**
   * The contract table, as it reads for a shape with no presentational keys.
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
      // The base names nothing, so a key is a key. `size` is only a sentinel
      // to the shapes that say so — see ::testPresentationalKeysArePerShape().
      'a `size` key is a value like any other' => [['size' => 'lg'], FALSE],
      'size plus content is a value' => [['size' => 'lg', 'src' => 'a.png'], FALSE],
    ];
  }

  /**
   * Each case resolves per the contract.
   */
  #[DataProvider('contractCases')]
  public function testContract(mixed $value, bool $expected): void {
    $this->assertSame($expected, $this->shape(StringShape::class)->isProvidedValueEmpty($value));
  }

  /**
   * Which keys are presentational is each shape's own answer.
   *
   * The list used to live on the base, which meant every shape in the system
   * discounted `size` — including a component author's object prop whose only
   * child happened to carry that name. Such a prop resolved as empty and was
   * dropped from its parent, so this pins the discount to the two shapes that
   * have a reason for it.
   */
  public function testPresentationalKeysArePerShape(): void {
    // The media modifier seeds `size` onto image props and nowhere else, so a
    // value carrying only it is a placeholder the fallback must be allowed to
    // replace.
    $image = $this->shape(ImageShape::class);
    $this->assertTrue($image->isProvidedValueEmpty(['size' => 'lg']));
    $this->assertFalse($image->isProvidedValueEmpty(['size' => 'lg', 'src' => 'a.png']));

    // A heading resolves `size` to `md` unasked and treats `anchor` as a link
    // target rather than text; neither is something to render.
    $heading = $this->shape(HeadingShape::class);
    $this->assertTrue($heading->isProvidedValueEmpty(['size' => 'md', 'anchor' => 'somewhere']));
    $this->assertFalse($heading->isProvidedValueEmpty(['size' => 'md', 'title' => 'Hello']));

    // Any other shape reads both as content.
    $string = $this->shape(StringShape::class);
    $this->assertFalse($string->isProvidedValueEmpty(['size' => 'lg']));
    $this->assertFalse($string->isProvidedValueEmpty(['anchor' => 'somewhere']));
  }

  /**
   * Builds a shape without running its constructor.
   *
   * @param class-string $class
   *   The shape class to build.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The bare shape.
   */
  private function shape(string $class): ComponentShapePluginInterface {
    /** @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape */
    $shape = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    return $shape;
  }

}
