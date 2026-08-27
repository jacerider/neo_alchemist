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
  prop: 'telephone',
  label: new TranslatableMarkup('Telephone'),
  default_field_type: 'telephone',
  default_field_widget: 'telephone_default',
  text_keys: TRUE,
)]
class TelephoneShape extends ComponentShapePluginBase {

}
