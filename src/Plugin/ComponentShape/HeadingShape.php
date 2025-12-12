<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo\Helpers\Str;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'heading',
  label: new TranslatableMarkup('Heading'),
)]
class HeadingShape extends ObjectShape {

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['anchor']['widget']['widget'][0]['value']['#neo_size'] = 'xs';
    $form['anchor']['widget']['widget'][0]['value']['#slug']['source'] = array_merge($form['title']['widget']['widget'][0]['value']['#field_parents'], [
      'widget',
      'widget',
      0,
      'value',
    ]);
    $form['size']['#neo_size'] = 'xs';
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    // Use the anchor value if provided, otherwise generate from title.
    $anchor = $value['anchor'] ?? ($value['title'] ? Str::machine($value['title'], '-') : NULL);
    $attributes->setAttribute('id', $anchor);
    $attributes->addClass('scroll-mt-neo-t');
    if ($value['title'] ?? NULL) {
      $attributes->setAttribute('data-component-title', $value['title']);
    }
    return $value;
  }

}
