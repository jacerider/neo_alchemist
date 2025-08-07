<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'breadcrumb',
  label: new TranslatableMarkup('Breadcrumb'),
  default_plugins: ['breadcrumb'],
)]
class BreadcrumbShape extends ArrayShape {

}
