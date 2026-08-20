<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\Shape\ComponentShapePluginBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'slug',
  label: new TranslatableMarkup('Slug'),
  default_field_type: 'string',
  default_field_widget: 'slug',
)]
class SlugShape extends ComponentShapePluginBase {

  /**
   * Holds already used slug values to ensure uniqueness.
   *
   * @var array
   */
  protected static $slugs = [];

  /**
   * {@inheritDoc}
   */
  public function init(): ComponentShapePluginInterface {
    parent::init();
    $this->getOptionDefault()->setAccess(FALSE);
    $this->getOptionEmpty()->setAccess(FALSE);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    $form['widget'][0]['value']['#field_prefix'] = '#';
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = parent::preRenderValue($value, $attributes);
    if ($value) {
      if (in_array($value, self::$slugs)) {
        $suffix = 2;
        while (in_array($value . '-' . $suffix, self::$slugs)) {
          $suffix++;
        }
        $value .= '-' . $suffix;
      }
      self::$slugs[] = $value;
    }
    return $value;
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
