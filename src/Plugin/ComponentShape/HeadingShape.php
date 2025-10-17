<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'heading',
  label: new TranslatableMarkup('Heading'),
)]
class HeadingShape extends ObjectShape {

}
