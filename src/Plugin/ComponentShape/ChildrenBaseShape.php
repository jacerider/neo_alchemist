<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentValueModifierPluginManager;
use Drupal\neo_alchemist\ComponentValueProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A trait for adding the module handler.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class ChildrenBaseShape extends ComponentShapePluginBase implements ComponentShapeChildrenPluginInterface {

  use ShapeManagerDependentShapeTrait;

  /**
   * The child shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected $childShapes;

  /**
   * {@inheritDoc}
   */
  public function getChildShapesFromSchema(array $schema): array {
    if (!isset($this->childShapes)) {
      // If we are acting on a single property, we prevent setting the object
      // to default as we rely on the single prop to set the default.
      if ($this->isSingleProp()) {
        $this->setOptionDefaultAccess(FALSE);
      }
      $value = $this->getFieldItemValue();
      // ksm($value);
      $this->childShapes = array_map(function ($shape) use ($value) {
        // We add the parent shapes to the child shape.
        foreach ($this->getParentShapes() as $parentShape) {
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
        foreach ($this->getNestedValueProviders() as $nestedId => $providers) {
          if ($shape->getNestedId() === $nestedId) {
            foreach ($providers as $providerId => $settings) {
              $shape->addValueProvider($providerId, $settings);
            }
          }
          elseif (substr($nestedId, 0, strlen($shape->getNestedId())) === $shape->getNestedId()) {
            foreach ($providers as $providerId => $settings) {
              $shape->addNestedValueProvider($nestedId, $providerId, $settings);
            }
          }
        }
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
        // Initialize the shape.
        $shape->init();
        return $shape;
      }, $this->shapeManager->getInstancesFromSchema($schema, $this->getComponent()));

      // Check if the object has required properties. If so, we allow the prop
      // to be set as empty.
      $hasRequired = !empty(array_filter($this->childShapes, fn ($shape) => $shape->isRequired()));
      $this->setOptionEmptyAccess($hasRequired);
    }
    return $this->childShapes;
  }

}
