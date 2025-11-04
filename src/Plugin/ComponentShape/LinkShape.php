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
class LinkShape extends UrlShapeBase {

  use ModuleHandlerDependentShapeTrait;

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
