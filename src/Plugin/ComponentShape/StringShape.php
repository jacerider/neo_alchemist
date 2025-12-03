<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'string',
  label: new TranslatableMarkup('String'),
  default_field_type: 'string',
  default_field_type_with_options: 'list_string',
  default_field_widget: 'string_textfield',
  default_field_widget_with_options: 'options_select',
  supports_field_types: ['datetime'],
  supports_field_props: ['string', 'integer', 'float', 'decimal', 'email'],
  formats: [
    'textarea' => [
      'default_field_type' => 'string_long',
      'default_field_widget' => 'string_textarea',
    ],
  ]
)]
class StringShape extends ComponentShapePluginBase {

  /**
   * {@inheritDoc}
   */
  protected function buildValue(): mixed {
    $value = parent::buildValue();
    if (is_string($value) && $value !== strip_tags($value)) {
      $value = Markup::create($value);
    }
    if (empty($value)) {
      // Always return a string if empty.
      $value = '';
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public static function getGenerationExamples(array $prop) {
    return 'Example string';
  }

  /**
   * {@inheritDoc}
   */
  public static function onGenerateTwig(NeoComponentTwig $twig) {
    parent::onGenerateTwig($twig);
    $twig->setContent('<div>{{' . $twig->getName() . '}}</div>');
    return $twig;
  }

}
