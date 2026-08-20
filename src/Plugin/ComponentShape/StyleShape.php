<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapeStyleAttribute;

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
  public function getFieldOptions(): array {
    $labels = array_map(
      static fn (array $option): string => $option['label'],
      $this->getStyleOptions()
    );
    return $this->filterStyleSettings($labels);
  }

  /**
   * {@inheritDoc}
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    if (isset($form['widget']['#options']['_none'])) {
      $form['widget']['#options']['_none'] = $this->isRequired() ? $this->t('- Default -') : $this->t('- None -');
    }
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    // The child/aggregate path can hand this an array rather than the style
    // key string — a delta-keyed field value ([{value: x}]) or a
    // block-claimed empty's []. Resolve to the key where one exists; an
    // empty/unresolvable value renders an empty attribute.
    if (is_array($value)) {
      $value = $value['value'] ?? $value[0]['value'] ?? NULL;
    }
    $finalValue = new ComponentShapeStyleAttribute([], $value ?: NULL);
    if ($value && isset($this->schema['styles'][$value]['value'])) {
      // Split multi-class values into individual class tokens so consumers can
      // manipulate them (e.g. `gap.removeClass('neo-section')` in twig) and
      // merging dedupes per class instead of per value string.
      $finalValue->addClass(preg_split('/\s+/', trim((string) $this->schema['styles'][$value]['value'])) ?: []);
    }
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $attributes->merge($finalValue);
    }
    return $finalValue;
  }

}
