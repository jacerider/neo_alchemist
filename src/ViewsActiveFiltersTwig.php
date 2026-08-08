<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;

/**
 * The views_active_filters prop value: applied-filter chips plus helpers.
 *
 * ArrayAccess proxies the underlying value ({{ active_filters.items }},
 * {{ active_filters.count }}, {{ active_filters.clear_url }}); the methods add
 * the accessibility wiring a hand-written chip link tends to omit.
 *
 * @see \Drupal\neo_alchemist\ViewsFilterTwig
 */
final class ViewsActiveFiltersTwig implements \ArrayAccess, \Countable, \JsonSerializable {

  use StringTranslationTrait;

  public function __construct(
    protected readonly array $value,
  ) {}

  /**
   * The attributes for one chip's remove link.
   *
   * @param mixed $item
   *   An entry from items.
   *
   * @return \Drupal\Core\Template\Attribute
   *   href + a descriptive aria-label + rel="nofollow" (filter permutations
   *   are not pages worth crawling).
   */
  public function getLink($item): Attribute {
    return new Attribute(array_filter([
      'href' => $item['remove_url'] ?? NULL,
      'aria-label' => (string) $this->t('Remove @filter: @value', [
        '@filter' => (string) ($item['filter_label'] ?? ''),
        '@value' => (string) ($item['label'] ?? ''),
      ]),
      'rel' => 'nofollow',
    ]));
  }

  /**
   * The attributes for the clear-all link.
   *
   * @return \Drupal\Core\Template\Attribute
   *   href + aria-label + rel="nofollow".
   */
  public function getClearLink(): Attribute {
    return new Attribute(array_filter([
      'href' => $this->value['clear_url'] ?? NULL,
      'aria-label' => (string) $this->t('Remove all filters'),
      'rel' => 'nofollow',
    ]));
  }

  /**
   * Returns the wrapped value.
   *
   * @return array
   *   The plain data.
   */
  public function toArray(): array {
    return $this->value;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists(mixed $offset): bool {
    return isset($this->value[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet(mixed $offset): mixed {
    return $this->value[$offset] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetSet(mixed $offset, mixed $value): void {
    throw new \LogicException('The views_active_filters value is read-only in Twig.');
  }

  /**
   * {@inheritdoc}
   */
  public function offsetUnset(mixed $offset): void {
    throw new \LogicException('The views_active_filters value is read-only in Twig.');
  }

  /**
   * {@inheritdoc}
   *
   * SDC prop validation JSON-encodes prop values before validating them
   * against the schema; serializing to the wrapped data keeps this object
   * validating exactly like the plain array it carries.
   *
   * @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
   */
  public function jsonSerialize(): array {
    return $this->value;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->value);
  }

}
