<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeStyleAttribute;
use Drupal\neo_color\Element\Scheme;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'scheme',
  label: new TranslatableMarkup('Color Scheme'),
  default_field_type: 'neo_scheme',
  default_field_widget: 'neo_scheme',
  default_plugins: [
    'widget' => [
      'settings' => [
        'include' => ['default', 'primary', 'secondary', 'accent'],
      ],
    ],
  ],
)]
class SchemeShape extends StyleShapeBase {

  /**
   * {@inheritDoc}
   */
  public function init(): self {
    $this->setRequired(TRUE);
    return parent::init();
  }

  /**
   * {@inheritdoc}
   */
  public function getStyleOptions(): array {
    $options = [];
    // Scheme options are defined in code (as neo_scheme entities) rather than
    // in a prop def `styles` list, so expose the full, unfiltered set here.
    foreach (Scheme::getSchemes() as $scheme) {
      $options[$scheme->id()] = [
        'label' => (string) $scheme->label(),
        'value' => $scheme->getSelector(),
      ];
    }
    return $options;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): array {
    $options = parent::getFieldOptions();

    if ($this->isInitialized()) {
      $config = $this->getWidgetSettings();
      $schemes = Scheme::getSchemes($config['allow_dark'] ?? TRUE, $config['allow_color'] ?? TRUE, $config['include'] ?? [], $config['exclude'] ?? []);
      foreach ($schemes as $scheme) {
        $options[$scheme->id()] = $scheme->label();
      }
    }

    // Honor the site-wide include/exclude configuration, consistent with
    // StyleShape. This layers on top of the per-component widget include/exclude
    // applied above.
    return $this->filterStyleSettings($options);
  }

  /**
   * {@inheritDoc}
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    // The neo_scheme widget builds its option list directly from
    // Scheme::getSchemes() and never consults getFieldOptions(), so the
    // site-wide include/exclude configuration (neo_alchemist.style_settings)
    // that getFieldOptions() applies via filterStyleSettings() never reaches
    // the rendered picker. Constrain the widget to exactly the shape's allowed
    // options by funneling their ids through the element's #include list.
    if (isset($form['widget']) && $allowed = array_keys($this->getFieldOptions())) {
      $existing = array_filter((array) ($form['widget']['#include'] ?? []));
      $form['widget']['#include'] = $existing
        ? array_values(array_intersect($existing, $allowed))
        : $allowed;
    }
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    $target_id = $value['target_id'] ?? $value;
    $finalValue = new ComponentShapeStyleAttribute([], $target_id);
    if ($target_id && is_string($target_id)) {
      /** @var \Drupal\neo_color\SchemeInterface $scheme */
      $scheme = $this->entityTypeManager->getStorage('neo_scheme')->load($target_id);
      if ($scheme) {
        $finalValue->addClass($scheme->getSelector());
      }
    }
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $attributes->merge($finalValue);
    }
    return $finalValue;
  }

}
