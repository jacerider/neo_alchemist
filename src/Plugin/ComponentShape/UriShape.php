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
  prop: 'uri',
  label: new TranslatableMarkup('URI'),
)]
class UriShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'uri';
  }

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    return 'uri';
  }

}
