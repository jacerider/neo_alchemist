<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'string',
  label: new TranslatableMarkup('String'),
  default_field_type: 'string',
  default_field_type_with_options: 'list_string',
  default_field_widget: 'string_textfield',
  default_field_widget_with_options: 'options_select',
  supports_field_types: ['datetime'],
  // `timestamp` covers created/changed/timestamp fields, which all expose a
  // single `value` property of that data type. It has to be listed explicitly:
  // the Timestamp data type extends IntegerData in PHP, but these lists are
  // compared by plugin id, so `integer` above does not cover it.
  // `datetime_iso8601` picks up daterange, whose four properties keep it out of
  // the whole-field branch; datetime itself already matches by field type.
  supports_field_props: ['string', 'integer', 'float', 'decimal', 'email', 'timestamp', 'datetime_iso8601'],
  formats: [
    'textarea' => [
      'default_field_type' => 'string_long',
      'default_field_widget' => 'string_textarea',
    ],
  ],
  text_keys: TRUE,
)]
class StringShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if (is_string($value) && $value !== strip_tags($value)) {
      $value = Markup::create($value);
    }
    if ($this->isProvidedValueEmpty($value)) {
      // Always return a string if empty. Deliberately not empty(): '0' and 0
      // are values a string prop can legitimately hold, and flattening them
      // to '' silently dropped authored content.
      $value = '';
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public static function getGenerationExamples(array $prop) {
    return 'Example string';
  }

  /**
   * {@inheritDoc}
   */
  public static function onGenerateTwig(NeoComponentTwig $twig) {
    parent::onGenerateTwig($twig);
    $twig->setContent('<div>{{' . $twig->getName() . '}}</div>');
    return $twig;
  }

}
