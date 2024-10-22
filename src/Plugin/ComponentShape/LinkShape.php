<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'link',
  label: new TranslatableMarkup('Link'),
)]
class LinkShape extends ComponentShapePluginBase {

  use ModuleHandlerDependentShapeTrait;

  /**
   * {@inheritDoc}
   */
  protected function getDefaultFieldType(): string {
    return 'link';
  }

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    if ($this->moduleHandler->moduleExists('linkit')) {
      return 'linkit';
    }
    return 'link_default';
  }

}
