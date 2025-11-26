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
   * A list of child shapes to set as locked.
   *
   * @var bool[]
   */
  protected $lockChildShapes = [];

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
    if (!isset($this->childSchema[$delta])) {
      $childSchema = $this->loadChildSchema($delta);
      if (!empty($childSchema['required'])) {
        foreach ($childSchema['properties'] as $propName => &$prop) {
          if (in_array($propName, $childSchema['required'])) {
            if (empty($prop['examples'])) {
              // When a property is required and the default value is empty,
              // we set the examples to the default value.
              // This is to ensure that the required property has a value.
              $defaultValue = $this->getDefaultFieldItemValue();
              $prop['examples'] = !is_null($delta) ? $defaultValue[$delta][$propName] ?? [] : $defaultValue[$propName] ?? [];
            }
          }
        }
      }
      $this->childSchema[$delta] = $childSchema;
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
  public function getChildShapes(int|null $delta = NULL, mixed $value = NULL): array {
    $key = $delta ?? 'all';
    if (!isset($this->childShapes[$key])) {
      $this->childShapes[$key] = $this->loadChildShapes($delta, $value);
    }
    else {
      foreach ($this->childShapes[$key] as $shape) {
        if (!empty($value[$shape->getName()])) {
          $shape->setFieldItemValue($value[$shape->getName()]);
        }
      }
    }
    return $this->childShapes[$key];
  }

  /**
   * {@inheritDoc}
   */
  public function hideChildShape(string $shapeId, $hide = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before hiding child shapes.');
    if ($this->isRoot()) {
      $this->hideChildShapes[$shapeId] = $hide;
    }
    else {
      return $this->getChildRootShape()->hideChildShape($shapeId, $hide);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isHiddenChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->hideChildShapes[$shapeId] ?? NULL;
    }
    else {
      return $this->getChildRootShape()->isHiddenChildShape($shapeId);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function defaultChildShape(string $shapeId, $default = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before defaulting child shapes.');
    if ($this->isRoot()) {
      $this->defaultChildShapes[$shapeId] = $default;
    }
    else {
      return $this->getChildRootShape()->defaultChildShape($shapeId, $default);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isDefaultChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->defaultChildShapes[$shapeId] ?? NULL;
    }
    else {
      return $this->getChildRootShape()->isDefaultChildShape($shapeId);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function lockChildShape(string $shapeId, $lock = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before locking child shapes.');
    if ($this->isRoot()) {
      $this->lockChildShapes[$shapeId] = $lock;
    }
    else {
      return $this->getChildRootShape()->lockChildShape($shapeId, $lock);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isLockedChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->lockChildShapes[$shapeId] ?? NULL;
    }
    else {
      return $this->getChildRootShape()->isLockedChildShape($shapeId);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function enableChildShapePlugin(string $shapeId, string $pluginId, array $settings = []): self {
    if ($this->isRoot()) {
      $this->childShapePlugins[$shapeId][$pluginId] = [
        'status' => TRUE,
        'settings' => $settings,
      ];
    }
    else {
      return $this->getChildRootShape()->enableChildShapePlugin($shapeId, $pluginId, $settings);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function disableChildShapePlugin(string $shapeId, string $pluginId): self {
    if ($this->isRoot()) {
      $this->childShapePlugins[$shapeId][$pluginId]['status'] = FALSE;
    }
    else {
      return $this->getChildRootShape()->disableChildShapePlugin($shapeId, $pluginId);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapePlugins(string $shapeId): array {
    if ($this->isRoot()) {
      $plugins = [];
      if ($this->isIterable()) {
        // Merge in plugins stored on root shape. We only need to do this for
        // iterable shapes as non-iterable shapes will automatically be pulled
        // from the base shape because they do not have a delta.
        foreach ($this->getPlugins()[$shapeId] ?? [] as $plugin_id => $plugin) {
          $plugins[$plugin_id] = [
            'status' => TRUE,
            'settings' => $plugin['settings'],
          ];
        }
      }
      return $plugins + ($this->childShapePlugins[$shapeId] ?? []);
    }
    else {
      return $this->getChildRootShape()->getChildShapePlugins($shapeId);
    }
    return [];
  }

  /**
   * Gets the root shape that implements ComponentShapeChildrenPluginInterface.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface
   *   The root shape that implements ComponentShapeChildrenPluginInterface.
   */
  protected function getChildRootShape(): ComponentShapeChildrenPluginInterface {
    if ($this->isRoot()) {
      return $this;
    }
    else {
      $rootShape = $this->getRootShape();
      if ($rootShape instanceof ComponentShapeChildrenPluginInterface) {
        return $rootShape;
      }
    }
    throw new \RuntimeException('Root shape does not implement ComponentShapeChildrenPluginInterface.');
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapeNames(): array {
    return array_keys($this->getChildSchemaProperties());
  }

  /**
   * {@inheritDoc}
   */
  public function getChildShapeRefs(): array {
    return array_map(function ($property) {
      return $property['ref'] ?? $property['type'];
    }, $this->getChildSchemaProperties());
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
   * @param mixed $value
   *   (optional) The value to set on the child shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of child shapes.
   */
  protected function loadChildShapes(int|null $delta = NULL, mixed $value = []): array {
    $shapes = $this->getChildShapesFromSchema($this->getChildSchema($delta), $delta);
    $count = count($shapes);
    $value = $value ?? $this->getOverrideValue();
    array_walk($shapes, fn ($shape) => $this->initChildShape($shape, $count, $delta, $value[$shape->getName()] ?? NULL));
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
   * @param array $value
   *   The override value for the child shape.
   */
  protected function initChildShape(ComponentShapePluginInterface $shape, int $count, ?int $delta = NULL, mixed $value = []) {
    $val = $this->isHiddenChildShape($shape->id(TRUE));
    if (!is_null($val)) {
      $shape->getOptionEmpty()->setLockedValue($val, 'Shape is hidden by parent shape.');
    }
    $val = $this->isDefaultChildShape($shape->id(TRUE));
    if (!is_null($val)) {
      $shape->getOptionDefault()->setLockedValue($val, 'Shape is set as default by parent shape.');
    }
    $val = $this->isLockedChildShape($shape->id(TRUE));
    if (!is_null($val)) {
      $shape->getOptionAccess()->setLockedValue($val, 'Shape is set as locked by parent shape.');
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
    if ($this->getScope() !== 'config') {
      // This has been removed and now restored. This means it may cause issues
      // somewhere else. But we need it for things like a heading shape so that
      // children are hidden.
      if ($this->getOptionDefault()->isEnabled()) {
        $shape->getOptionDefault()->setLockedValue(TRUE, 'Parent shape is set as default, so set child shape as default.');
      }
      if ($this->getOptionEmpty()->isEnabled()) {
        $shape->getOptionEmpty()->setLockedValue(TRUE, 'Parent shape is set as empty, so set child shape as empty.');
      }
      if ($this->getOptionAccess()->isDisabled()) {
        $shape->getOptionAccess()->setLockedValue(FALSE, 'Parent shape is disabled.');
      }
    }
    if ($this->getScope() === 'config') {
      $shape->getOptionAccess()->setAccess(TRUE, 'Scope is config.');
    }
    if ($this instanceof ComponentShapeExpandedPluginInterface && !$this->allowExpanded()) {
      $shape->getOptionDefault()->setAccess(FALSE, 'Parent shape is not expandable.');
      $shape->getOptionEmpty()->setAccess(FALSE, 'Parent shape is not expandable.');
      $shape->getOptionAccess()->setAccess(FALSE, 'Parent shape is not expandable.');
    }
    // Set the override value.
    if (!is_null($value)) {
      $shape->setParentValue($value);
    }
    // Add child shape plugins.
    if ($plugins = $this->getChildShapePlugins($shape->id(TRUE))) {
      foreach ($plugins as $pluginId => $settings) {
        // Prevent this plugin from being initialized automatically by the
        // shape.
        $shape->allowInitPlugins($pluginId, FALSE);
        if ($settings['status']) {
          $shape->addPlugin($pluginId, $settings['settings'] ?? []);
        }
      }
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

}
