<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'uri',
  label: new TranslatableMarkup('URI'),
  default_field_type: 'uri',
  default_field_widget: 'uri',
)]
class UriShape extends ComponentShapePluginBase {

}
