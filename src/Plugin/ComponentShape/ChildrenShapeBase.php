<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Field\FieldDefinitionInterface;
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
  use ChildShapeStateTrait;

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
   * Whether a set of child shapes was built before any value was offered.
   *
   * Keyed exactly like ::$childShapes. Read only by the assertion in
   * ::getChildShapes(), which is meaningful only for that case.
   *
   * @var bool[]
   */
  protected array $childShapesBuiltValueless = [];

  /**
   * Uninitialized child shapes used only for value resolution.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]|null
   */
  protected ?array $valueResolverShapes = NULL;

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
      // Record whether the children are being built before any value was
      // offered. That ordering is the whole subject of the assertion below.
      $this->childShapesBuiltValueless[$key] = $value === NULL;
      $this->childShapes[$key] = $this->loadChildShapes($delta, $value);
    }
    else {
      foreach ($this->childShapes[$key] as $shape) {
        // Skip only genuinely valueless slices. In the usual field-item
        // format a falsy scalar arrives wrapped (['value' => '0']) and was
        // never at risk here, but flat slices (schema examples, resolved
        // values) do reach this branch — and the skip predicate must match
        // the cold path's null-check, or warming the shapes changes what
        // resolves. 0, '0' and FALSE are values.
        $childValue = is_array($value) ? ($value[$shape->getName()] ?? NULL) : NULL;
        if ($childValue === NULL || $shape->isProvidedValueEmpty($childValue)) {
          continue;
        }
        // Do not overwrite a child that resolves its own value from a value
        // provider (e.g. an entity field). Otherwise a non-editable parent —
        // whose own value falls back to the SDC example — pushes that example
        // down and clobbers the child provider's value. Children with no
        // provider of their own still receive the parent value as before.
        if ($this->childHasOwnValueProvider($shape)) {
          // Refusing the push is only safe while the child can still produce a
          // value some other way: either it already holds one, or its "default"
          // option is on and it will fall back to the schema example. A child
          // that is empty *and* cannot fall back has just had the parent's
          // authored value dropped on the floor, and the prop renders as
          // nothing — silently, with no error anywhere.
          //
          // That is the failure this shape tree hit when the per-delta priming
          // in loadChildShapes() ran before the value was available: the shapes
          // were already cached by the time the value arrived here, and every
          // media prop inside an array silently lost its authored content. Trip
          // loudly in development instead — assertions are compiled out in
          // production, so this costs nothing there.
          //
          // That ordering is the whole of the failure, so the assertion is
          // scoped to it. Children built with a value in hand already had their
          // chance at it, and a later refusal drops nothing. Scoping it any
          // other way means judging whether the offer carries content, which
          // cannot be done by inspection: the form passes route through here
          // too (ArrayShape::validateForm() warms the cache,
          // ::massageFormValues() then lands in this branch) carrying raw
          // submitted input, where the child of a media prop reads
          // ['image' => ['open_button' => 'Add media', …]] — button labels and
          // option wrappers that are structure, not content, yet
          // indistinguishable from it. Asserting there turned every "Add media"
          // click on an as-yet-unchosen media child inside an array into a 500
          // for the editor.
          //
          // @see ::loadChildShapes()
          assert(
            !($this->childShapesBuiltValueless[$key] ?? FALSE) || !$shape->isEmpty() || $shape->getOptionDefault()->isEnabled(),
            sprintf(
              'Child shape "%s" of "%s" was refused the value its parent offered while holding no value of its own and no default to fall back to, so authored content is being dropped.',
              $shape->id(TRUE),
              $this->id(TRUE),
            ),
          );
          continue;
        }
        $shape->setFieldItemValue($childValue);
      }
    }
    return $this->childShapes[$key];
  }

  /**
   * Determine if we should force child default values.
   *
   * @return bool
   *   Whether to force child default values.
   */
  protected function forceChildDefaultValues(): bool {
    return !$this->hasOverrideValue() && $this->isExpanded();
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
  public function getAutoMatchProperties(FieldDefinitionInterface $sourceField): array {
    // Only child names are read here (a pure schema lookup). We deliberately
    // avoid getChildShapes()/getChildSchema(), which route through
    // getDefaultValue() and would recurse when this runs during value
    // resolution (see getValueResolverShapes()).
    $childNames = $this->getChildShapeNames();
    if (!$childNames) {
      return [];
    }

    $storage = $sourceField->getFieldStorageDefinition();
    $sourcePropertyNames = array_keys($storage->getPropertyDefinitions());

    $map = [];
    $unmatched = [];
    // 1. Exact name match. Reproduces the raw automatic behaviour, where the
    // children distribution keys the field value by child prop name (e.g. an
    // image field's alt/width/height line up by name).
    foreach ($childNames as $childName) {
      if (in_array($childName, $sourcePropertyNames, TRUE)) {
        $map[$childName] = $childName;
      }
      else {
        $unmatched[] = $childName;
      }
    }

    // 2. Guarded main-property fallback. When exactly one child is left
    // unmatched and the field exposes a main property, route it there. This
    // makes an entity_reference/media field (main property target_id) or a
    // link field (main property uri) populate a child prop whose name never
    // matches a field property. The single-unmatched guard keeps the blast
    // radius bounded: multi-child objects are left unchanged.
    if (count($unmatched) === 1) {
      $mainProperty = $storage->getMainPropertyName();
      if ($mainProperty && in_array($mainProperty, $sourcePropertyNames, TRUE) && !in_array($mainProperty, $map, TRUE)) {
        $map[reset($unmatched)] = $mainProperty;
      }
    }

    return $map;
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
    // Only distribute the stored override to children when this parent would
    // itself use it. A non-editable (or "use default") expanded parent must not
    // inject its stored per-instance value into children — mirror init()'s gate
    // so each child's own value providers run instead of the stale value.
    if (is_null($value)) {
      $value = $this->isEditable() && !$this->getOptionDefault()->isEnabled()
        ? $this->getOverrideValue()
        : NULL;
      // An iterable parent's override value is keyed by delta, not by child
      // prop name, so narrow it to this delta before it is distributed below —
      // exactly what getChildShapeList() passes in on the value-carrying call.
      // Without this the lookup is $value['<child>'] against a delta-keyed
      // array, which always misses, so every child of every delta is primed
      // with NULL. That only surfaces when the shapes are built before the
      // value-carrying call — getAllShapes(includeDeltas: TRUE), which
      // ComponentTreeHydrated::getValue() runs on every live render — because
      // getChildShapes() then takes its cached branch, where a child owning a
      // value provider (e.g. an image's media plugin) refuses the pushed-down
      // value. The child is left with no value at all and renders the schema
      // example, or nothing where the author turned "default" off.
      if ($delta !== NULL && is_array($value) && $this->isIterable()) {
        $value = $value[$delta] ?? NULL;
      }
    }
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

  /**
   * Get uninitialized child shapes for value resolution.
   *
   * NOT getChildShapes()/getChildSchema(): those route through
   * loadChildSchema() -> getDefaultValue() and would recurse infinitely,
   * since resolveValue() runs while getDefaultValue() is being memoized.
   * These instances are never init()'d; resolveValue() must not need it.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The uninitialized child shapes, keyed by property name.
   */
  protected function getValueResolverShapes(): array {
    if (!isset($this->valueResolverShapes)) {
      $properties = $this->getChildSchemaProperties();
      $this->valueResolverShapes = $properties ? $this->getChildShapesFromSchema([
        'type' => 'object',
        'properties' => $properties,
      ]) : [];
    }
    return $this->valueResolverShapes;
  }

  /**
   * {@inheritDoc}
   */
  public function getValueResolverShape(string $name): ?ComponentShapePluginInterface {
    return $this->getValueResolverShapes()[$name] ?? NULL;
  }

  /**
   * Resolve each child slice of a value through its child shape.
   *
   * Keys with no matching child shape (such as `_weight`) pass through
   * untouched.
   *
   * @param array $value
   *   The value, keyed by child property name.
   *
   * @return array
   *   The value with each child slice resolved.
   */
  protected function resolveChildValues(array $value): array {
    foreach ($this->getValueResolverShapes() as $name => $shape) {
      if (array_key_exists($name, $value)) {
        $value[$name] = $shape->resolveValue($value[$name]);
      }
    }
    return $value;
  }

  /**
   * Determines whether a child shape supplies its own value from a provider.
   *
   * A child with an active value provider resolves its own value and must not
   * have it overwritten by a value pushed down from its parent. Without this,
   * a non-editable parent — whose own value falls back to the SDC example —
   * pushes that example down and clobbers the child provider's value. The case
   * it was added for is a `media` child inside an expanded object prop.
   *
   * Membership in the "providers" group IS the declaration that a plugin
   * sources a value, so this is a plain group check with no plugin ids in it.
   * Plugins that touch a prop without sourcing anything live in the groups that
   * describe what they actually do — `fallback` (`default`, which only fills an
   * empty value), `modifiers` (`formatted_text`, which renders an existing
   * value through a text format) and `settings` (`widget`, `region_size`,
   * `region_custom`, which configure the prop and never touch its value).
   *
   * Getting that taxonomy wrong is silent and destructive: counting a
   * non-sourcing plugin here makes the child refuse its parent's stored value
   * and fall back to the schema's `examples`, so authored content — a table
   * cell, an FAQ answer, a section intro — renders as the component's example
   * text where an example exists at that index, and as nothing where it does
   * not. Only children take a value pushed down from a parent, so a top-level
   * prop never shows the symptom.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The child shape.
   *
   * @return bool
   *   TRUE if the child has its own active value provider.
   *
   * @see \Drupal\neo_alchemist\ComponentShapePluginCollection::getActiveInstances()
   */
  protected function childHasOwnValueProvider(ComponentShapePluginInterface $shape): bool {
    return (bool) $shape->getValueCollection()->getActiveInstances('providers');
  }

}
