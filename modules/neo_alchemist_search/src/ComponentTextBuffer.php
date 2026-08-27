<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;

/**
 * Collects extracted text runs, normalised and de-duplicated.
 *
 * Both extraction halves append here, so normalisation, de-duplication and the
 * length cap live in one place rather than being reimplemented per source. The
 * de-duplication is deliberately global rather than per-half: a prop can be
 * both authored and bound, and the same string legitimately repeats within one
 * component — a card whose title and link title are both "Renewables". Solr's
 * term-frequency saturation means the repeat buys almost nothing anyway.
 */
final class ComponentTextBuffer {

  /**
   * Normalised text runs, keyed by themselves so insertion order survives.
   *
   * @var array<string, string>
   */
  private array $texts = [];

  /**
   * Running total of the collected characters, including separators.
   */
  private int $length = 0;

  /**
   * Constructs a ComponentTextBuffer.
   *
   * @param int $maxLength
   *   The character budget for one entity. A layout large enough to exhaust it
   *   is pathological; the cap exists so it degrades instead of exploding.
   */
  public function __construct(
    private readonly int $maxLength = 50000,
  ) {}

  /**
   * Normalises a raw value and collects it when it carries text.
   *
   * @param mixed $raw
   *   The stored value. Anything that is not a string is dropped: numeric
   *   leaves are not content, and arrays mean the caller walked to the wrong
   *   depth.
   * @param bool $stripHtml
   *   TRUE for rich-text sources, whose markup would otherwise become
   *   searchable tokens.
   */
  public function add(mixed $raw, bool $stripHtml = FALSE): void {
    if (!is_string($raw) || $this->isFull()) {
      return;
    }
    if ($stripHtml) {
      // Tags become a space rather than nothing. Removing them outright welds
      // adjacent blocks together — "<p>Experience:</p><p>0+ years</p>" would
      // index as "Experience:0+", a token matching neither word.
      $raw = (string) preg_replace('#<[^>]*+>#', ' ', $raw);
      // Anything left is a stray angle bracket rather than real markup.
      $raw = strip_tags($raw);
    }
    $text = trim((string) preg_replace('/\s+/u', ' ', Html::decodeEntities($raw)));
    if ($text === '' || isset($this->texts[$text])) {
      return;
    }
    // Nothing to search for in a run with no letters or digits. Decorative
    // punctuation is common in these layouts — a lone "." is a real stored
    // value, used as a full stop that renders in an accent colour.
    if (!preg_match('/[\p{L}\p{N}]/u', $text)) {
      return;
    }
    // +1 for the separator this run will be joined with.
    $remaining = $this->maxLength - $this->length;
    if (mb_strlen($text) + 1 > $remaining) {
      $text = Unicode::truncate($text, max(0, $remaining - 1), TRUE);
      if ($text === '' || isset($this->texts[$text])) {
        $this->length = $this->maxLength;
        return;
      }
    }
    $this->texts[$text] = $text;
    $this->length += mb_strlen($text) + 1;
  }

  /**
   * Whether the character budget is spent.
   */
  public function isFull(): bool {
    return $this->length >= $this->maxLength;
  }

  /**
   * The collected runs, in the order they were added.
   *
   * @return string[]
   *   One string per text run. The field indexes these as separate values so
   *   Solr's position increment gap keeps a phrase query from matching across
   *   the boundary between two unrelated components.
   */
  public function toArray(): array {
    return array_values($this->texts);
  }

  /**
   * Whether nothing was collected.
   */
  public function isEmpty(): bool {
    return $this->texts === [];
  }

}
