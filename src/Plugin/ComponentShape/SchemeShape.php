<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

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
   * {@inheritDoc}
   */
  public function getFieldOptions(): ?array {
    $options = parent::getFieldOptions();

    if ($this->isInitialized()) {
      $config = $this->getWidgetSettings();
      $schemes = Scheme::getSchemes($config['allow_dark'] ?? TRUE, $config['allow_color'] ?? TRUE, $config['include'] ?? [], $config['exclude'] ?? []);
      foreach ($schemes as $scheme) {
        $options[$scheme->id()] = $scheme->label();
      }
    }

    return $options;
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
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
