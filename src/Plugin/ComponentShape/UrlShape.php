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
  prop: 'url',
  label: new TranslatableMarkup('Url'),
)]
class UrlShape extends ComponentShapePluginBase {

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

  /**
   * Get the default field instance settings.
   *
   * @return array
   *   The default field instance settings.
   */
  protected function getDefaultFieldInstaceSettings(): array {
    return [
      'title' => FALSE,
    ];
  }

}
