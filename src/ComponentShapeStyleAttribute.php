<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Template\Attribute;

/**
 * An attribute class for component shape styles.
 */
class ComponentShapeStyleAttribute extends Attribute {

  /**
   * The value of the shape.
   *
   * @var string|null
   */
  protected ?string $shapeValue = NULL;

  /**
   * Constructs a \Drupal\Core\Template\Attribute object.
   *
   * @param array $attributes
   *   An associative array of key-value pairs to be converted to attributes.
   * @param string|null $shapeValue
   *   The value of the shape, if applicable.
   */
  public function __construct($attributes = [], ?string $shapeValue = NULL) {
    parent::__construct($attributes);
    $this->shapeValue = $shapeValue;
  }

  /**
   * Gets the value of the shape.
   *
   * @return string|null
   *   The value of the shape, if applicable.
   */
  public function getValue(): ?string {
    return $this->shapeValue;
  }

}
