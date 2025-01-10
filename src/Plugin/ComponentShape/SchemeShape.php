<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'scheme',
  label: new TranslatableMarkup('Color Scheme'),
  default_field_type: 'neo_scheme',
  default_field_widget: 'neo_scheme',
)]
class SchemeShape extends StyleShapeBase {

  /**
   * {@inheritDoc}
   */
  public function getPropValue(): mixed {
    $originalValue = parent::getPropValue();
    $value = new Attribute();
    if (!empty($originalValue['target_id'])) {
      /** @var \Drupal\neo_color\SchemeInterface $scheme */
      $scheme = $this->entityTypeManager->getStorage('neo_scheme')->load($originalValue['target_id']);
      if ($scheme) {
        $value->addClass($scheme->getSelector());
      }
    }
    return $value;
  }

}
