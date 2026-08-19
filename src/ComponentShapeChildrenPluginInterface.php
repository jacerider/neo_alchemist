<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeChildrenPluginInterface extends ComponentShapeChildrenMatchPluginInterface {

  /**
   * Get the refs of the child shapes.
   *
   * @return string[]
   *   The refs of the child shapes.
   */
  public function getChildShapeRefs(): array;

  /**
   * Derive a default child-shape to field-property mapping for a source field.
   *
   * Used to populate children props automatically (without "Manually assign
   * properties"), routing each source field property to the child prop that
   * expects it - notably a field's main property (e.g. an entity-reference
   * target_id) to a lone unmatched child.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $sourceField
   *   The source field definition to map from.
   *
   * @return array
   *   A child-shape-name => field-property-name map. May be empty.
   */
  public function getAutoMatchProperties(FieldDefinitionInterface $sourceField): array;

}
