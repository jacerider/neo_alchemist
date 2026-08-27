<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist_search\ComponentTextBuffer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers how collected text is normalised before it reaches an index.
 */
#[Group('neo_alchemist_search')]
final class ComponentTextBufferTest extends UnitTestCase {

  /**
   * Only strings are collected.
   */
  public function testOnlyStringsAreCollected(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add('kept');
    $buffer->add(42);
    $buffer->add(3.5);
    $buffer->add(TRUE);
    $buffer->add(NULL);
    $buffer->add(['nested']);

    $this->assertSame(['kept'], $buffer->toArray());
  }

  /**
   * Whitespace is collapsed and the run is trimmed.
   */
  public function testWhitespaceIsNormalised(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add("  spread   over \n\n several \t lines  ");

    $this->assertSame(['spread over several lines'], $buffer->toArray());
  }

  /**
   * Runs carrying no letters or digits are dropped.
   *
   * These layouts really do store a lone "." as a heading part, rendered in an
   * accent colour. It is decoration, and nobody searches for it.
   */
  public function testPunctuationOnlyRunsAreDropped(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add('.');
    $buffer->add('—');
    $buffer->add('   ');
    $buffer->add('');
    $buffer->add('0');

    // "0" survives: it carries a digit, so it is at least searchable.
    $this->assertSame(['0'], $buffer->toArray());
  }

  /**
   * Identical runs are collected once, in first-seen order.
   */
  public function testDuplicatesAreDroppedPreservingOrder(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add('first');
    $buffer->add('second');
    $buffer->add('first');
    $buffer->add('  first  ');

    $this->assertSame(['first', 'second'], $buffer->toArray());
  }

  /**
   * Markup becomes text, with tags separating rather than welding words.
   */
  public function testMarkupIsStrippedWithSeparation(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add('<p>Experience:</p><p>0+ years</p>', TRUE);

    // Not "Experience:0+", which would match neither word.
    $this->assertSame(['Experience: 0+ years'], $buffer->toArray());
  }

  /**
   * Entities are decoded so the index sees the character, not the escape.
   */
  public function testEntitiesAreDecoded(): void {
    $buffer = new ComponentTextBuffer();
    $buffer->add('<p>Bachelor&rsquo;s degree &amp; more</p>', TRUE);

    $this->assertSame(['Bachelor’s degree & more'], $buffer->toArray());
  }

  /**
   * Collection stops once the character budget is spent.
   */
  public function testLengthCapStopsCollection(): void {
    $buffer = new ComponentTextBuffer(20);
    $this->assertFalse($buffer->isFull());

    $buffer->add(str_repeat('a', 12));
    $buffer->add(str_repeat('b', 40));
    $buffer->add('never reached');

    $this->assertTrue($buffer->isFull());
    $collected = $buffer->toArray();
    $this->assertNotContains('never reached', $collected);
    $this->assertLessThanOrEqual(20, array_sum(array_map('mb_strlen', $collected)));
  }

  /**
   * A buffer nothing was added to reports itself empty.
   */
  public function testEmptyBuffer(): void {
    $buffer = new ComponentTextBuffer();
    $this->assertTrue($buffer->isEmpty());
    $buffer->add('something');
    $this->assertFalse($buffer->isEmpty());
  }

}
