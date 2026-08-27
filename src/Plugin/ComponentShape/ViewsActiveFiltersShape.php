<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ViewsActiveFiltersTwig;

/**
 * Plugin implementation of the neo_component_shape.
 *
 * Wraps the views_active_filters value in its Twig helper object so preview
 * example data and provider-resolved data offer the same link()/clearLink()
 * methods.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ViewsFilterShape
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsActiveFiltersValue
 */
#[ComponentShape(
  prop: 'views_active_filters',
  label: new TranslatableMarkup('Views active filters'),
  // The summary of which filters are applied — interface furniture.
  text_keys: FALSE,
)]
class ViewsActiveFiltersShape extends ObjectShape {

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if (is_array($value) && $value) {
      return new ViewsActiveFiltersTwig($value);
    }
    return $value;
  }

}
