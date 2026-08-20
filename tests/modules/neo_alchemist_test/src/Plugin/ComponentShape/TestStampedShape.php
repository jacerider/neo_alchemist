<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\StringShape;

/**
 * A string shape that signs the attributes it was handed while rendering.
 *
 * Render attributes are threaded down the value-building chain, and the object
 * that reaches a nested shape has to be the *same* one the component builds its
 * wrapper from — StyleShape merges its classes into it, so a copy would render
 * nothing and still pass every assertion about values. Nothing in the shipped
 * shapes exposes which object it was given, so this one writes its id onto it.
 *
 * Behaving otherwise exactly like a string keeps the rest of the assertions
 * readable.
 *
 * @see \Drupal\Tests\neo_alchemist\Kernel\ShapeRenderAttributeThreadTest
 */
#[ComponentShape(
  prop: 'test_stamped',
  label: new TranslatableMarkup('Test stamped string'),
  default_field_type: 'string',
  default_field_widget: 'string_textfield',
)]
class TestStampedShape extends StringShape {

  /**
   * The attribute this shape writes its own id into.
   */
  public const STAMP = 'data-na-stamp';

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $attributes->setAttribute(self::STAMP, $this->id(TRUE));
    return parent::preRenderValue($value, $attributes);
  }

}
