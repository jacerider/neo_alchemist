<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeStyleAttribute;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'style',
  label: new TranslatableMarkup('Style'),
  default_field_type: 'list_string',
  default_field_widget: 'options_select',
)]
class StyleShape extends StyleShapeBase {

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): ?array {
    if (array_key_exists('styles', $this->schema)) {
      return array_map(function ($style) {
        return $style['label'] ?? 'Unnamed';
      }, $this->schema['styles']);
    }
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    if (isset($form['widget']['#options']['_none'])) {
      $form['widget']['#options']['_none'] = $this->t('- Default -');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getPropValue(Attribute $attributes): mixed {
    $originalValue = parent::getPropValue($attributes);
    $value = new ComponentShapeStyleAttribute([], $originalValue ?: NULL);
    if ($originalValue && isset($this->schema['styles'][$originalValue]['value'])) {
      $value->addClass($this->schema['styles'][$originalValue]['value']);
    }
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $attributes->merge($value);
    }
    return $value;
  }

}
