<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

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
  supports_field_props: ['string', 'integer', 'float', 'decimal', 'email'],
)]
class StringShape extends ComponentShapePluginBase {

}
