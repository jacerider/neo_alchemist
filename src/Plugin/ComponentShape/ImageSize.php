<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'image_size',
  label: new TranslatableMarkup('Image Size'),
  default_field_type: 'map',
)]
class ImageSize extends StyleShapeBase {

  /**
   * {@inheritDoc}
   */
  public function init(): ComponentShapePluginInterface {
    $this->setRequired(TRUE);
    return parent::init();
  }

  /**
   * {@inheritDoc}
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $form['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Size'),
      '#title_display' => 'invisible',
      '#options' => $this->getFieldOptions(),
      '#default_value' => $this->getValue(),
      '#required' => $this->isRequired(),
    ];
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): array {
    $options = parent::getFieldOptions();
    if (!$options) {
      // If we have no options, check for styles in the schema.
      if (array_key_exists('styles', $this->schema)) {
        foreach ($this->schema['styles'] as $key => $style) {
          $options[$key] = $style['label'];
        }
      }
    }
    return $options;
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    $finalValue = [];
    $styles = $this->schema['styles'] ?? [];
    if ($styles) {
      $styleId = $value['value'] ?? key($styles);
      if ($styleId && isset($styles[$styleId])) {
        $style = $styles[$styleId];
        $op = $style['value']['op'] ?? NULL;
        $width = $style['value']['width'] ?? NULL;
        $height = $style['value']['height'] ?? NULL;
        if (!$op) {
          $op = match (TRUE) {
            isset($width, $height) => 'focal',
            default => 'scale',
          };
        }
        $finalValue = [
          $op => array_filter([
            'width' => $width,
            'height' => $height,
          ]),
        ];
      }
    }
    return $finalValue;
  }

}
