<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'markup',
  label: new TranslatableMarkup('Markup'),
  default_field_type: 'string_long',
  default_field_widget: 'string_textarea',
  supports_field_types: ['string_long'],
  supports_field_props: ['string_long'],
  default_plugins: ['formatted_text'],
)]
class MarkupShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): mixed {
    if (is_array($value) && isset($value['value'])) {
      // The #text property needs to be a string and not an array.
      $value = $value['value'];
    }
    if (is_array($value)) {
      return $value;
    }
    // This prop expects a string value, so we ensure it is rendered as such.
    return Markup::create($value);
  }

}
