<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\HeadingTag;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The parts of a heading's tag that are read from source alone.
 *
 * @see \Drupal\neo_alchemist\PageHeading
 */
#[Group('neo_alchemist')]
class HeadingTagTest extends UnitTestCase {

  /**
   * A heading's fallback is h2, or the h2–h6 its prop declares.
   */
  #[DataProvider('fallbackCases')]
  public function testFallback(array $schema, string $expected): void {
    $this->assertSame($expected, HeadingTag::fallback($schema));
  }

  /**
   * Fallback cases.
   */
  public static function fallbackCases(): array {
    return [
      'none declared' => [[], 'h2'],
      'h3' => [['tag' => 'h3'], 'h3'],
      'upper case' => [['tag' => 'H4'], 'h4'],
      // The H1 is decided per page; a prop cannot make every heading one.
      'h1 is not a fallback' => [['tag' => 'h1'], 'h2'],
      'not a heading tag' => [['tag' => 'div'], 'h2'],
    ];
  }

  /**
   * A prop opts out of being the H1 only with an explicit false.
   */
  public function testAllowsH1(): void {
    $this->assertTrue(HeadingTag::allowsH1([]));
    $this->assertTrue(HeadingTag::allowsH1(['h1' => TRUE]));
    $this->assertFalse(HeadingTag::allowsH1(['h1' => FALSE]));
  }

  /**
   * Whether a template prints a heading's tag.
   */
  #[DataProvider('printsTagCases')]
  public function testPrintsTag(string $twig, string $name, bool $expected): void {
    $this->assertSame($expected, HeadingTag::printsTag($twig, $name));
  }

  /**
   * Template cases.
   */
  public static function printsTagCases(): array {
    return [
      'prints it' => ['<{{ heading.tag }}>{{ heading.title }}</{{ heading.tag }}>', 'heading', TRUE],
      'in an array item' => ['{% for slide in slides %}<{{ slide.heading.tag }}>{% endfor %}', 'heading', TRUE],
      'subscript' => ["<{{ heading['tag'] }}>", 'heading', TRUE],
      'another heading' => ['<{{ title.tag }}>', 'heading', FALSE],
      'a literal tag' => ['<h2>{{ heading.title }}</h2>', 'heading', FALSE],
      'a longer key' => ['<{{ subheading.tag }}>', 'heading', FALSE],
      'a longer property' => ['{{ heading.tagline }}', 'heading', FALSE],
      'only in a comment' => ['{# <{{ heading.tag }}> #}<h2>{{ heading.title }}</h2>', 'heading', FALSE],
    ];
  }

  /**
   * A literal h1, outside comments.
   */
  public function testPrintsLiteralH1(): void {
    $this->assertTrue(HeadingTag::printsLiteralH1('<h1 class="x">{{ heading.title }}</h1>'));
    $this->assertTrue(HeadingTag::printsLiteralH1('<H1>'));
    $this->assertFalse(HeadingTag::printsLiteralH1('<{{ heading.tag }}>'));
    $this->assertFalse(HeadingTag::printsLiteralH1('<h10><header>'));
    $this->assertFalse(HeadingTag::printsLiteralH1('{# <h1> #}'));
  }

}
