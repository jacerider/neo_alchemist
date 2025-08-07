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
  prop: 'address',
  label: new TranslatableMarkup('Address'),
  default_field_type: 'address',
  default_field_widget: 'address_default',
  provider: 'address',
)]
class AddressShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  public function getValue(): array {
    // Null values are converted to empty strings for JSON Schema compliance.
    return array_map(function ($item) {
      return $item ?? '';
    }, parent::getValue());
  }

}
