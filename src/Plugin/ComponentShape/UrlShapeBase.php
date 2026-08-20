<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;

/**
 * Base shape for URL-based components.
 */
class UrlShapeBase extends ComponentShapePluginBase {

  use UrlShapeTrait;

}
