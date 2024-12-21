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
   * @return array
   *   An array of child shapes generated from the schema.
   */
  protected function getChildShapesFromSchema(array $schema): array {
    $value = $this->getFieldItemValue();
    if ($this->getNestedId() === 'sequence') {
      // Expiremental code to add media plugin to sequence~image.
      // $plugins = $this->getPlugins();
      // $plugins['sequence~image']['media'] = [
      //   'id' => 'media',
      //   'settings' => [
      //     'status' => TRUE,
      //   ],
      // ];
      // $this->setPlugins($plugins);
    }
    $childShapes = array_map(function ($shape) use ($value) {
      // We add the parent shapes to the child shape.
      foreach ($this->getAllParentShapes() as $parentShape) {
        $shape->addParentShape($parentShape);
      }
      // Add the current shape as a parent shape.
      $shape->addParentShape($this);
      // Get nested options from parent shape and set them on the shape.
      $shape->setOptions($this->getNestedOptions($shape->getNestedId()));
      // Set the override value.
      $shape->setOverrideValue($value[$shape->getName()] ?? NULL);
      // Get nested value providers from parent shape and set them on the
      // shape.
      $shape->setPlugins($this->getPlugins());
      // foreach ($this->getNestedValueProviders() as $nestedId => $providers) {
      //   if ($shape->getNestedId() === $nestedId) {
      //     foreach ($providers as $providerId => $settings) {
      //       $shape->addValueProvider($providerId, $settings);
      //     }
      //   }
      //   elseif (substr($nestedId, 0, strlen($shape->getNestedId())) === $shape->getNestedId()) {
      //     foreach ($providers as $providerId => $settings) {
      //       $shape->addNestedValueProvider($nestedId, $providerId, $settings);
      //     }
      //   }
      // }
      // Get nested value modifiers from parent shape and set them on the
      // shape.
      foreach ($this->getNestedValueModifiers() as $nestedId => $modifiers) {
        if ($shape->getNestedId() === $nestedId) {
          foreach ($modifiers as $modifierId => $settings) {
            $shape->addValueModifier($modifierId, $settings);
          }
        }
        elseif (substr($nestedId, 0, strlen($shape->getNestedId())) === $shape->getNestedId()) {
          foreach ($modifiers as $modifierId => $settings) {
            $shape->addNestedValueModifier($nestedId, $modifierId, $settings);
          }
        }
      }
      if ($this->isOptionDefault()) {
        $shape->setOptionDefault(TRUE, TRUE);
      }
      if ($this->isOptionEmpty()) {
        $shape->setOptionEmpty(TRUE, TRUE);
      }
      return $shape;
    }, $this->shapeManager->getInstancesFromSchema($schema, $this->getComponent()));
    return $childShapes;
  }

}
