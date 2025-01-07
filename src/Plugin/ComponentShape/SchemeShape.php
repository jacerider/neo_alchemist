<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'scheme',
  label: new TranslatableMarkup('Color Scheme'),
  default_field_type: 'neo_scheme',
  default_field_widget: 'neo_scheme',
)]
class SchemeShape extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

  // /**
  //  * {@inheritDoc}
  //  */
  // public function getFieldOptions(): ?array {

  //   /** @var \Drupal\neo_color\SchemeInterface[] $schemes */
  //   $schemes = \Drupal::entityTypeManager()->getStorage('neo_scheme')->loadByProperties($properties);

  //   return NULL;
  // }

  /**
   * {@inheritDoc}
   */
  public function modifyAttributes(Attribute $attributes) {
    $value = $this->getValue();
    if (!empty($value['target_id'])) {
      /** @var \Drupal\neo_color\SchemeInterface $scheme */
      $scheme = $this->entityTypeManager->getStorage('neo_scheme')->load($value['target_id']);
      if ($scheme) {
        $attributes->addClass($scheme->getSelector());
      }
    }
  }

}
