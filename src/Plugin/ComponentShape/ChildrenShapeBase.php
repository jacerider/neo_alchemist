<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginBase;

/**
 * A trait for adding the module handler.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class ChildrenShapeBase extends ComponentShapePluginBase implements ComponentShapeChildrenPluginInterface {

  use ShapeManagerDependentShapeTrait;

  /**
   * Retrieves child shapes from the provided schema.
   *
   * This method processes the schema to generate child shapes, setting various
   * options and values based on the parent shape's properties and the provided
   * schema. It handles nested options, value providers, and value modifiers,
   * and ensures that the appropriate default and empty options are set.
   *
   * Please note that these shapes are not initialized.
   *
   * @param array $schema
   *   The schema array from which to generate child shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of child shapes generated from the schema.
   */
  protected function getChildShapesFromSchema(array $schema): array {
    $value = $this->getFieldItemValue();
    $childShapes = array_map(function ($shape) use ($value) {
      // We add the parent shapes to the child shape.
      foreach ($this->getParentShapes() as $parentShape) {
        $shape->addParentShape($parentShape);
      }
      // Add the current shape as a parent shape.
      $shape->addParentShape($this);
      $shape->setOverrideValue($value[$shape->getName()] ?? NULL);
      if ($this->getOptionDefault()->isEnabled()) {
        $shape->getOptionDefault()->setLockedValue(TRUE, 'Set by parent shape.');
      }
      if ($this->getOptionEmpty()->isEnabled()) {
        $shape->getOptionEmpty()->setLockedValue(TRUE, 'Set by parent shape.');
      }
      if ($this->getOptionAccess()->isDisabled()) {
        $shape->getOptionAccess()->setLockedValue(FALSE, 'Set by parent shape.');
      }
      if ($this->getScope() === 'config') {
        $shape->getOptionAccess()->setAccess(TRUE);
      }
      return $shape;
    }, $this->shapeManager->getInstancesFromSchema($schema, $this->getComponent()));
    return $childShapes;
  }

}
