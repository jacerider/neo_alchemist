<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentStyle;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentStyle;
use Drupal\neo_alchemist\ComponentStylePluginBase;

/**
 * Plugin implementation of the neo_component_style.
 */
#[ComponentStyle(
  id: 'padding',
  label: new TranslatableMarkup('Padding'),
  description: new TranslatableMarkup('The inner spacing.'),
  class_prefix: 'p',
  class_variations: [
    't' => 'Top',
    'r' => 'Right',
    'b' => 'Bottom',
    'l' => 'Left',
    'x' => 'Horizontal',
    'y' => 'Vertical',
  ],
  class_values: [
    'xs' => 'Extra small',
    'sm' => 'Small',
    'md' => 'Medium',
    'lg' => 'Large',
    'xl' => 'Extra large',
    '2xl' => '2x large',
  ],
)]
final class Padding extends ComponentStylePluginBase {

  public function getCssVariables(): array {
    return [
      '--spacing-xs' => 'spacing.1',
      '--spacing-sm' => 'spacing.4',
      '--spacing-md' => 'spacing.6',
      '--spacing-lg' => 'spacing.10',
      '--spacing-xl' => 'spacing.16',
      '--spacing-2xl' => 'spacing.24',
    ];
  }

}
