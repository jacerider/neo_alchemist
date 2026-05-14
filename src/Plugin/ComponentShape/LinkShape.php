<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use DrupalCodeGenerator\InputOutput\Interviewer;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'link',
  label: new TranslatableMarkup('Link'),
  default_field_type: 'link',
  default_field_widget: 'neo_link',
)]
class LinkShape extends StructuredObjectShapeBase {

  use UrlShapeTrait;

  /**
   * {@inheritdoc}
   */
  protected function alterFieldItemValue(mixed &$value): void {
    if (isset($value['uri']) && is_array($value['uri'])) {
      // When using the matcher, the value may come in as a nested array with
      // the URI under 'uri' and URI options under 'options'.
      $value['options'] = $value['uri']['options'] ?? $value['options'] ?? [];
      $value['uri'] = $value['uri']['uri'] ?? '';
    }
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): mixed {
    if (!empty($value)) {
      if (empty($value['icon']) && !empty($value['options']['attributes']['data-icon'])) {
        $value['icon'] = $value['options']['attributes']['data-icon'];
      }
    }
    return parent::adaptValue($value);
  }

  /**
   * {@inheritDoc}
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
    $prop['examples'] = [
      'uri' => 'internal:/',
      'title' => 'Example link',
    ];
  }

}
