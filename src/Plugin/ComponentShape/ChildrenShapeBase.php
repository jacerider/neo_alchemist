<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapeExpandedPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * A trait for adding the module handler.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
abstract class ChildrenShapeBase extends ComponentShapePluginBase implements ComponentShapeChildrenPluginInterface {

  use ShapeManagerDependentShapeTrait;

  /**
   * The child schema.
   */
  protected array $childSchema = [];

  /**
   * The child shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[][]
   */
  protected $childShapes;

  /**
   * A list of child shapes to hide.
   *
   * @var bool[]
   */
  protected $hideChildShapes = [];

  /**
   * A list of child shapes to set as default.
   *
   * @var bool[]
   */
  protected $defaultChildShapes = [];

  /**
   * A list of child shape plugins.
   *
   * @var array[]
   */
  protected $childShapePlugins = [];

  /**
   * Get the schema properties.
   *
   * Can be called before the child has been initialized.
   *
   * @return array
   *   The schema properties.
   */
  abstract protected function getChildSchemaProperties(): array;

  /**
   * Retrieves the cached schema for the child component.
   *
   * This method returns the schema definition for the child component by
   * calling the `getSchema` method.
   *
   * Can only be called after the child has been initialized.
   *
   * @param int|null $delta
   *   The delta of the field item, if applicable.
   *
   * @return array
   *   The schema definition array for the child component.
   */
  protected function getChildSchema(int|null $delta = NULL): array {
    assert($this->isInitialized(), 'Shape must be initialized before calling getChildShapes().');
    if (!isset($this->childSchema[$delta]) || TRUE) {
      $this->childSchema[$delta] = $this->loadChildSchema($delta);
    }
    return $this->childSchema[$delta];
  }

  /**
   * Retrieves the schema for the child component.
   *
   * This method returns the schema definition for the child component by
   * calling the `getSchema` method.
   *
   * @param int|null $delta
   *   The delta of the field item, if applicable.
   *
   * @return array
   *   The schema definition array for the child component.
   */
  abstract protected function loadChildSchema(int|null $delta = NULL): array;

  /**
   * {@inheritDoc}
   */
  public function getChildShapes(int|null $delta = NULL): array {
    $key = $delta ?? ($this->getType() === ComponentShapePluginInterface::ARRAY ? 0 : 'all');
    if (!isset($this->childShapes[$key])) {
      $this->childShapes[$key] = $this->loadChildShapes($delta);
    }
    return $this->childShapes[$key];
  }

  /**
   * {@inheritDoc}
   */
  public function hideChildShape(string $shapeName, $hide = TRUE): self {
    $this->hideChildShapes[$shapeName] = $hide;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function defaultChildShape(string $shapeName, $default = TRUE): self {
    $this->defaultChildShapes[$shapeName] = $default;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setChildShapePlugins(string $shapeName, array $plugins): self {
    $this->childShapePlugins[$shapeName] = $plugins;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapeNames(): array {
    return array_keys($this->getChildSchemaProperties());
  }

  /**
   * Load the child shapes.
   *
   * This method loads the child shapes from the schema, initializing them
   * and setting various options and values based on the parent shape's
   * properties and the provided schema.
   *
   * @param int|null $delta
   *   The delta of the field item, if applicable.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of child shapes.
   */
  protected function loadChildShapes(int|null $delta = NULL): array {
    $shapes = $this->getChildShapesFromSchema($this->getChildSchema($delta), $delta);
    $count = count($shapes);
    $value = $this->getOverrideValue();
    if ($delta !== NULL) {
      $value = $value[$delta] ?? [];
    }
    array_walk($shapes, fn ($shape) => $this->initChildShape($shape, $count, $delta, $value));
    return $shapes;
  }

  /**
   * Initializes a child shape.
   *
   * This method initializes a child shape, setting various options and values
   * based on the parent shape's properties and the provided schema.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The child shape to initialize.
   * @param int $count
   *   The number of child shapes.
   * @param int|null $delta
   *   The delta of the field item, if applicable.
   * @param mixed $value
   *   The override value of the parent shape.
   */
  protected function initChildShape(ComponentShapePluginInterface $shape, int $count, int|null $delta = NULL, $value) {
    $shapeName = $shape->getName();
    if (!empty($this->hideChildShapes[$shape->getName()])) {
      $shape->getOptionEmpty()->setLockedValue(TRUE, 'Shape is hidden by parent shape.');
    }
    if (!empty($this->defaultChildShapes[$shape->getName()])) {
      $shape->getOptionDefault()->setLockedValue(TRUE, 'Shape is set as default by parent shape.');
    }
    if ($delta !== NULL && $count === 1) {
      $shape->getOptionDefault()->setAccess(FALSE, 'Shape has a single prop, so setting as default is not allowed.');
      $shape->getOptionEmpty()->setAccess(FALSE, 'Shape has a single prop, so setting as default is not allowed.');
    }
    elseif ($this->isSingleProp()) {
      $shape->getOptionEmpty()->setAccess(FALSE, 'Shape has a single prop, so setting as empty is not allowed.');
    }
    if ($this->getOptionDefault()->isFormForced()) {
      $shape->getOptionDefault()->alwaysShowForm(TRUE, 'Parent shape has default form forced.');
    }
    if ($this->getOptionEmpty()->isFormForced()) {
      $shape->getOptionEmpty()->alwaysShowForm(TRUE, 'Parent shape has empty form forced.');
    }
    if ($this->getScope() === 'config') {
      $shape->getOptionAccess()->setAccess(TRUE, 'Scope is config.');
    }
    if ($this instanceof ComponentShapeExpandedPluginInterface && !$this->allowExpanded()) {
      $shape->getOptionDefault()->setAccess(FALSE, 'Parent shape is not expandable.');
      $shape->getOptionEmpty()->setAccess(FALSE, 'Parent shape is not expandable.');
      $shape->getOptionAccess()->setAccess(FALSE, 'Parent shape is not expandable.');
    }
    if (!empty($this->childShapePlugins[$shape->getName()])) {
      foreach ($this->childShapePlugins[$shape->getName()] as $pluginId => $settings) {
        $shape->addPlugin($pluginId, $settings);
      }
    }
    // Set the override value.
    if (isset($value[$shapeName])) {
      $shape->setOverrideValue($value[$shapeName] ?? []);
    }
    $shape->init();
  }

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
   * @param int|null $delta
   *   The delta of the field item, if applicable.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of child shapes generated from the schema.
   */
  protected function getChildShapesFromSchema(array $schema, $delta = NULL): array {
    $childShapes = array_map(function ($shape) use ($delta) {
      // We add the parent shapes to the child shape.
      foreach ($this->getParentShapes() as $parentShape) {
        $shape->addParentShape($parentShape);
      }
      // Add the current shape as a parent shape.
      $shape->addParentShape($this);
      // Add the delta.
      if ($delta !== NULL) {
        $shape->setDelta((int) $delta);
      }
      return $shape;
    }, $this->shapeManager->getInstancesFromSchema($schema, $this->getComponent()));
    return $childShapes;
  }

  /**
   * Check if parent values should be overlayed on top of child values.
   *
   * @return bool
   *   TRUE if parent values should be overlayed on top of child values, FALSE
   *   otherwise.
   */
  protected function useParentValues(): bool {
    if ($this->belongsToExpanded()) {
      return $this->hasOverrideValue();
    }
    return TRUE;
  }

}
