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
  prop: 'email',
  label: new TranslatableMarkup('Email'),
  default_field_type: 'email',
  default_field_widget: 'email_default',
)]
class EmailShape extends ComponentShapePluginBase {

}
