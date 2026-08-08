<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;

/**
 * The views_filter prop value: filter data plus its Twig wiring helpers.
 *
 * Modeled on neo_swiper's SwiperTwig: the template owns every byte of markup,
 * and this object hands over the wiring that a hand-written GET form can
 * silently get wrong — the method/action pair, the hidden inputs that
 * preserve the rest of the query string, and the input attribute clusters
 * whose parts must agree.
 *
 * ArrayAccess proxies the underlying value, so all plain data access keeps
 * working unchanged ({{ filter.options }}, {{ filter.active_count }}, …).
 * Method names use the get/has/is prefixes Drupal's Twig sandbox policy
 * allows on object method calls — the same constraint that shapes
 * SwiperTwig's API — which also keeps them clear of the data keys (Twig's
 * dot notation checks array access before methods).
 *
 * SDC prop validation accepts this object because it is JsonSerializable:
 * the validator JSON-encodes prop values first, so it sees the wrapped data
 * and the prop validates as a plain object. (Core's class-typed-prop support
 * is unusable here: with a mixed [object, FQCN] type it nullifies the value
 * but keeps `object` required.)
 *
 * @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ViewsFilterShape
 */
final class ViewsFilterTwig implements \ArrayAccess, \Countable, \JsonSerializable {

  public function __construct(
    protected readonly array $value,
  ) {}

  /**
   * The GET form attributes: method and action, always right.
   *
   * @return \Drupal\Core\Template\Attribute
   *   Attributes for the <form> tag.
   */
  public function getForm(): Attribute {
    return new Attribute(array_filter([
      'method' => 'get',
      'action' => $this->value['action'] ?? NULL,
    ]));
  }

  /**
   * Every other query arg as hidden inputs.
   *
   * Print this inside the filter's form so submitting it preserves the search
   * text and every sibling filter. Forgetting these is the one mistake that
   * fails silently — everything works until a visitor combines two filters.
   *
   * @return \Drupal\Core\Render\MarkupInterface
   *   The hidden input elements.
   */
  public function getHidden() {
    $html = '';
    foreach ($this->value['carry'] ?? [] as $pair) {
      $html .= '<input' . new Attribute([
        'type' => 'hidden',
        'name' => (string) $pair['name'],
        'value' => (string) $pair['value'],
      ]) . '>';
    }
    return Markup::create($html);
  }

  /**
   * The attribute cluster for one option as a checkbox (multi-select).
   *
   * @param mixed $option
   *   An entry from the options tree.
   *
   * @return \Drupal\Core\Template\Attribute
   *   type/name[]/value/checked, agreeing by construction. Chainable:
   *   <input{{ filter.getCheckbox(option).addClass('accent-primary') }}>.
   */
  public function getCheckbox($option): Attribute {
    $attributes = new Attribute([
      'type' => 'checkbox',
      'name' => ($this->value['param'] ?? '') . '[]',
      'value' => (string) ($option['value'] ?? ''),
    ]);
    if (!empty($option['active'])) {
      $attributes->setAttribute('checked', 'checked');
    }
    return $attributes;
  }

  /**
   * The attribute cluster for one option as a radio (single-select).
   *
   * @param mixed $option
   *   An entry from the options tree.
   *
   * @return \Drupal\Core\Template\Attribute
   *   type/name/value/checked.
   */
  public function getRadio($option): Attribute {
    $attributes = new Attribute([
      'type' => 'radio',
      'name' => (string) ($this->value['param'] ?? ''),
      'value' => (string) ($option['value'] ?? ''),
    ]);
    if (!empty($option['active'])) {
      $attributes->setAttribute('checked', 'checked');
    }
    return $attributes;
  }

  /**
   * The attribute cluster for a text filter's input.
   *
   * @param string $type
   *   The input type — 'text', or 'search' for search-box semantics.
   *
   * @return \Drupal\Core\Template\Attribute
   *   type/name/value (+ placeholder when the filter declares one).
   */
  public function getTextfield(string $type = 'text'): Attribute {
    $current = $this->value['value'] ?? [];
    $attributes = new Attribute([
      'type' => $type,
      'name' => (string) ($this->value['param'] ?? ''),
      'value' => (string) (is_array($current) ? (reset($current) ?: '') : $current),
    ]);
    if (!empty($this->value['placeholder'])) {
      $attributes->setAttribute('placeholder', (string) $this->value['placeholder']);
    }
    return $attributes;
  }

  /**
   * The attributes for one option rendered as a link.
   *
   * @param mixed $option
   *   An entry from the options tree.
   *
   * @return \Drupal\Core\Template\Attribute
   *   href, plus aria-current="true" when the option is active.
   */
  public function getLink($option): Attribute {
    $attributes = new Attribute(array_filter([
      'href' => $option['url'] ?? NULL,
    ]));
    if (!empty($option['active'])) {
      $attributes->setAttribute('aria-current', 'true');
    }
    return $attributes;
  }

  /**
   * Returns the wrapped value.
   *
   * @return array
   *   The plain filter data.
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
    throw new \LogicException('The views_filter value is read-only in Twig.');
  }

  /**
   * {@inheritdoc}
   */
  public function offsetUnset(mixed $offset): void {
    throw new \LogicException('The views_filter value is read-only in Twig.');
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
