<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\StringShape;

/**
 * A string shape that always owns a `providers`-group value plugin.
 *
 * This is the dependency-free twin of ImageShape for test purposes. ImageShape
 * declares `default_plugins: ['media']`, and because a non-expanded child
 * returns FALSE from allowConfigurablePlugins(), init() calls initPlugins() and
 * the provider ends up active on every instance — including every delta of an
 * array. That is precisely the condition under which
 * ChildrenShapeBase::getChildShapes() refuses a value pushed down by its
 * parent.
 *
 * Behaving otherwise exactly like a string keeps assertions readable: the
 * resolved value is the authored string, or the schema example, with no media
 * entity resolution in between to obscure which one was used.
 *
 * @see \Drupal\neo_alchemist_test\Plugin\ComponentValue\TestProviderValue
 */
#[ComponentShape(
  prop: 'test_provided',
  label: new TranslatableMarkup('Test provided string'),
  default_field_type: 'string',
  default_field_widget: 'string_textfield',
  default_plugins: ['na_test_provider'],
)]
class TestProvidedShape extends StringShape {

}
