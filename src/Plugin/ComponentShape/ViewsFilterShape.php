<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ViewsFilterTwig;

/**
 * Plugin implementation of the neo_component_shape.
 *
 * Exists for one job: wrapping the views_filter value in its Twig helper
 * object. The views_exposed_filter provider wraps what it resolves, but that
 * only covers bound props — the schema-example value that previews and
 * unbound props render must offer the same form()/hidden()/checkbox()/…
 * methods, or a template written against the helper breaks in preview.
 * preRenderValue() runs for both paths (before the provider's modify stage),
 * so wrapping here guarantees the template always sees one type.
 *
 * @see \Drupal\neo_alchemist\ViewsFilterTwig
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsExposedFilterValue
 */
#[ComponentShape(
  prop: 'views_filter',
  label: new TranslatableMarkup('Views filter'),
)]
class ViewsFilterShape extends ObjectShape {

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if (is_array($value) && $value) {
      return new ViewsFilterTwig($value);
    }
    return $value;
  }

}
