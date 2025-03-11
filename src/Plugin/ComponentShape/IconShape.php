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
  prop: 'icon',
  label: new TranslatableMarkup('Icon'),
  default_field_type: 'neo_icon',
  default_field_widget: 'neo_icon',
)]
class IconShape extends ComponentShapePluginBase {

}
