<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Render\MarkupInterface;

/**
 * Defines an object that passes safe strings through the render system.
 *
 * This object should only be constructed with a known safe string. If there is
 * any risk that the string contains user-entered data that has not been
 * filtered first, it must not be used.
 *
 * @internal
 *   This object is marked as internal because it should only be used whilst
 *   rendering.
 *
 * @see \Drupal\Core\Template\TwigExtension::escapeFilter
 * @see \Twig\Markup
 */
final class ComponentPropRenderable implements MarkupInterface, \Countable {

  /**
   * The build array.
   *
   * @var array
   */
  protected $value;

  /**
   * Creates a Markup object if necessary.
   *
   * If $string is equal to a blank string then it is not necessary to create a
   * Markup object. If $string is an object that implements MarkupInterface it
   * is returned unchanged.
   *
   * @param mixed $value
   *   The string to mark as safe. This value will be cast to a string.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   A safe string.
   */
  public static function create($value) {
    if ($value instanceof MarkupInterface) {
      return $value;
    }
    if (is_string($value)) {
      $value = [
        '#markup' => $value,
      ];
    }
    $instance = new static();
    $instance->value = $value;
    return $instance;
  }

  /**
   * Returns the render array.
   *
   * @return array
   *   The render array.
   */
  public function build() {
    return $this->value;
  }

  /**
   * Returns the string version of the Markup object.
   *
   * @return string
   *   The safe string content.
   */
  public function __toString() {
    $build = $this->value;
    if (is_string($build)) {
      $build = [
        '#markup' => $build,
      ];
    }
    return \Drupal::service('renderer')->render($build);
  }

  /**
   * Returns the string length.
   *
   * @return int
   *   The length of the string.
   */
  public function count(): int {
    return mb_strlen($this->jsonSerialize());
  }

  /**
   * Returns a representation of the object for use in JSON serialization.
   *
   * @return string
   *   The safe string content.
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}
