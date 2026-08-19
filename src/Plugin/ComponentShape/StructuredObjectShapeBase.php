<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * Base for shapes that expose structured subproperties for field matching.
 *
 * Use this base when a shape behaves like an object with named children
 * (e.g. LinkShape's uri/options/icon/target/access) but cannot extend
 * ChildrenShapeBase because it needs a different value-rendering pipeline.
 * Subclasses only need to declare getChildSchemaProperties().
 */
abstract class StructuredObjectShapeBase extends ComponentShapePluginBase implements ComponentShapeChildrenMatchPluginInterface {

  use ShapeManagerDependentShapeTrait;
  use ChildShapeStateTrait;

  /**
   * Cached child shapes, keyed by delta.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[][]
   */
  protected array $structuredChildShapes = [];

  /**
   * Uninitialized child shapes used only for value resolution.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]|null
   */
  protected ?array $valueResolverShapes = NULL;

  /**
   * Get the schema properties that define the structured children.
   *
   * Defaults to the prop's own schema `properties`. Override only if a shape
   * needs to expose a different set of children than its declared schema.
   *
   * @return array
   *   An associative array keyed by child name, each value being a partial
   *   schema fragment (must include at minimum a 'type' key).
   */
  protected function getChildSchemaProperties(): array {
    return $this->getSchema()['properties'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getChildShapeNames(): array {
    return array_keys($this->getChildSchemaProperties());
  }

  /**
   * {@inheritdoc}
   */
  public function isSingleProp(): bool {
    return count($this->getChildSchemaProperties()) === 1;
  }

  /**
   * {@inheritDoc}
   */
  public function getValueResolverShape(string $name): ?ComponentShapePluginInterface {
    if (!isset($this->valueResolverShapes)) {
      $properties = $this->getChildSchemaProperties();
      $this->valueResolverShapes = $properties ? $this->shapeManager->getChildInstancesFromSchema([
        'type' => 'object',
        'properties' => $properties,
      ], $this->getComponent()) : [];
      foreach ($this->valueResolverShapes as $shape) {
        foreach ($this->getParentShapes() as $parentShape) {
          $shape->addParentShape($parentShape);
        }
        $shape->addParentShape($this);
      }
    }
    return $this->valueResolverShapes[$name] ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  protected function buildValue(): mixed {
    $value = parent::buildValue();
    if (!$value) {
      return $value;
    }
    // Drop only genuinely empty children before backfilling from the schema
    // examples. A bare array_filter() here dropped authored 0/'0'/FALSE and
    // the merge below then silently replaced them with the component
    // author's placeholder content.
    //
    // Asked of $this rather than of each child's own shape, unlike ::147 below
    // and ObjectShape::buildValue(). Presentational keys are per-shape, so the
    // two forms differ the moment a child names one — and the only subclass
    // here, LinkShape, names none, so building the child shapes early to ask
    // them would buy nothing but an ordering risk. A subclass that does name
    // presentational keys has to switch this to the child-shape form, via
    // ::getValueResolverShape().
    $value = array_filter($value, fn ($childValue) => !$this->isProvidedValueEmpty($childValue));
    $value += $this->getDefaultSchemaValue();
    // Ensure we return cleaned values.
    foreach ($this->getChildShapes(NULL, $value) as $shapeName => $shape) {
      $value[$shapeName] = $shape->getValue();
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getChildShapes(int|null $delta = NULL, mixed $value = NULL): array {
    $key = $delta ?? 'all';
    if (!isset($this->structuredChildShapes[$key])) {
      $schema = [
        'type' => 'object',
        'properties' => $this->getChildSchemaProperties(),
      ];
      $instances = $this->shapeManager->getChildInstancesFromSchema($schema, $this->getComponent());
      $count = count($instances);
      foreach ($instances as $shape) {
        foreach ($this->getParentShapes() as $parentShape) {
          $shape->addParentShape($parentShape);
        }
        $shape->addParentShape($this);
        if ($delta !== NULL) {
          $shape->setDelta((int) $delta);
        }
        // Apply the parent's constraints before init(), which reads the options
        // this locks. Skipping it is what made a producer's hide/default/lock
        // configuration a no-op on this base while it took effect on the other.
        $this->childOptionPolicy()->apply($this, $shape, $delta, $count);
        // Propagate per-child plugin configuration a producer recorded in the
        // child shape state before initializing, so the plugins are attached
        // before init() collects them.
        if ($plugins = $this->getChildShapePlugins($shape->id(TRUE))) {
          foreach ($plugins as $pluginId => $settings) {
            $shape->allowInitPlugins($pluginId, FALSE);
            if (!empty($settings['status'])) {
              $shape->addPlugin($pluginId, $settings['settings'] ?? []);
            }
          }
        }
        // Seed the child with its slice of the parent value before init() so
        // it picks the value up via getParentValue() during initialization.
        $childValue = $value[$shape->getName()] ?? NULL;
        if (!is_null($childValue)) {
          $shape->setParentValue($childValue);
        }
        $shape->init();
      }
      $this->structuredChildShapes[$key] = $instances;
    }
    else {
      // Shapes are already loaded — push any new value through to them. The
      // skip predicate must match the cold path's null-check semantics:
      // falsy-but-real values (0, '0', FALSE) are pushed, so warming the
      // shapes cannot change what resolves.
      foreach ($this->structuredChildShapes[$key] as $shape) {
        $childValue = is_array($value) ? ($value[$shape->getName()] ?? NULL) : NULL;
        if ($childValue !== NULL && !$shape->isProvidedValueEmpty($childValue)) {
          $shape->setFieldItemValue($childValue);
        }
      }
    }
    return $this->structuredChildShapes[$key];
  }

}
