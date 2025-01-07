<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for neo_component_style plugins.
 */
abstract class ComponentStylePluginBase extends PluginBase implements ComponentStyleInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  public function getPrefix(): string {
    return $this->pluginDefinition['class_prefix'] ?? '';
  }

  public function getVariations(): array {
    return $this->pluginDefinition['class_variations'] ?? [];
  }

  public function getValues(): array {
    return $this->pluginDefinition['class_values'] ?? [];
  }

  public function getBreakpoints(): array {
    return [
      'md' => 'Medium',
      'lg' => 'Large',
    ];
  }

  public function getOptions(): array {
    $options = [];
    $prefix = $this->getPrefix();
    $variations = $this->getVariations();
    $values = $this->getValues();
    foreach ($values as $value => $valueLabel) {
      $options[($prefix ? $prefix . '-' : '') . $value] = $valueLabel;
      foreach ($variations as $variation => $variationLabel) {
        $options[$prefix . $variation . '-' . $value] = $variationLabel . ': ' . $valueLabel;
      }
    }
    return $options;
  }

  public function getOptionsWithBreakpoints(): array {
    $options = $this->getOptions();
    $baseOptions = $options;
    foreach ($this->getBreakpoints() as $breakpoint => $breakpointLabel) {
      foreach ($baseOptions as $option => $optionLabel) {
        $options[$breakpoint . ':' . $option] = '(' . $breakpointLabel . ') ' . $optionLabel;
      }
    }
    return $options;
  }

  public function getCssVariables(): array {
    return [];
  }

}
