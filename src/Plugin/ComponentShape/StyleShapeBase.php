<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;

/**
 * A base class for style shapes.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class StyleShapeBase extends ComponentShapePluginBase implements ComponentShapeStylePluginInterface {

  /**
   * {@inheritDoc}
   */
  public function init(): self {
    $this->getOptionEmpty()->setAccess(FALSE, 'Styles cannot be empty.');
    return parent::init();
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): mixed {
    $value = parent::getValue();
    if ($this->getComponent()->getScope() === 'config') {
      $previewValue = $this->getComponent()->getPreviewStyle($this->id());
      if ($previewValue !== NULL) {
        $value = $previewValue;
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function modifyAttributes(Attribute $attributes) {
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $value = $this->getPropValue();
      if ($value instanceof Attribute) {
        $attributes->merge($value);
      }
    }
  }

}
