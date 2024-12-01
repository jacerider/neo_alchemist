<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'link',
  label: new TranslatableMarkup('Link'),
  default_field_type: 'link',
  default_field_widget: 'link_default',
)]
class LinkShape extends ComponentShapePluginBase {

  use ModuleHandlerDependentShapeTrait;

  /**
   * {@inheritDoc}
   */
  protected function getWidgetType(): ?string {
    if ($this->moduleHandler->moduleExists('linkit')) {
      return 'linkit';
    }
    return parent::getWidgetType();
  }

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    return $entityFieldDefinition->getType() === 'link';
  }

}
