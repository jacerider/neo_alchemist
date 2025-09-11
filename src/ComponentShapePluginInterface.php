<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Access\AccessibleInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ObjectWithPluginCollectionInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapePluginInterface extends PluginInspectionInterface, DerivativeInspectionInterface, ObjectWithPluginCollectionInterface, AccessibleInterface, CacheableResponseInterface {

  const STRING = 'string';
  const NUMBER = 'number';
  const INTEGER = 'integer';
  const BOOLEAN = 'boolean';
  const ARRAY = 'array';
  const OBJECT = 'object';

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Retrieves the nested ID by concatenating the elements of the parent path.
   *
   * Each parent ID is separated by a period (.).
   *
   * @param bool $ignoreDelta
   *   Whether to ignore the delta when generating the ID.
   *
   * @return string
   *   The concatenated parent ID.
   */
  public function id(bool $ignoreDelta = FALSE): string;

  /**
   * Retrieves the path of parent component nested ids.
   *
   * This method iterates through the list of parent components and collects
   * their nested ids into an array.
   *
   * @param bool $includeRoot
   *   (optional) Whether to include the current component in the path. Defaults
   *   to TRUE.
   *
   * @return array
   *   An array of parent component nested ids.
   */
  public function ids($includeRoot = TRUE): array;

  /**
   * Get the nested delta of the shape.
   *
   * @return int|null
   *   The nested delta.
   */
  public function getDelta(): ?int;

  /**
   * Set the nested delta of the shape.
   *
   * @param int $delta
   *   The nested delta.
   *
   * @return $this
   */
  public function setDelta(int $delta): self;

  /**
   * Returns the initial settings for the shape.
   *
   * @return array
   *   An associative array of initial settings for the shape.
   */
  public function getSettings(): array;

  /**
   * Initialize the shape and calculates the value of the field item.
   *
   * This method processes the field item value by starting with the schema
   * defaults, then modifying with value providers, and finally overlaying
   * the user input if applicable.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function init(): self;

  /**
   * Allow or disallow initialization of specific plugins.
   *
   * If a shape has a default_plugins, this method can be used to prevent it
   * from being initialized.
   *
   * @param string $pluginId
   *   The ID of the plugin.
   * @param bool $allow
   *   Whether to allow initialization. Defaults to TRUE.
   *
   * @return $this
   */
  public function allowInitPlugins(string $pluginId, bool $allow = TRUE): self;

  /**
   * Checks if the shape is initialized.
   *
   * @return bool
   *   TRUE if the shape is initialized, FALSE otherwise.
   */
  public function isInitialized(): bool;

  /**
   * Called when the shape is added to a component.
   */
  public function onAdd(): void;

  /**
   * Called when the shape is removed from a component.
   */
  public function onRemove(): void;

  /**
   * Handles the event when a plugin is added to a component prop.
   *
   * @param string $pluginId
   *   The ID of the plugin being added.
   */
  public function onPluginAdd($pluginId): void;

  /**
   * Handles the event when a plugin is removed from a component prop.
   *
   * @param string $pluginId
   *   The ID of the plugin being removed.
   */
  public function onPluginRemove($pluginId): void;

  /**
   * Get the schema.
   *
   * @return array
   *   The schema.
   */
  public function getSchema(): array;

  /**
   * Get the prop type.
   *
   * This is the type of the prop.
   *
   * Can be 'string', 'number', 'integer', 'boolean', 'array', 'object'.
   *
   * @return string
   *   The prop name.
   */
  public function getType(): string;

  /**
   * Get the prop ref.
   *
   * This is the reference to the prop.
   *
   * @return string
   *   The prop ref.
   */
  public function getRef(): string;

  /**
   * Get the prop format.
   *
   * This is the optional format of the prop.
   *
   * @return string
   *   The prop format.
   */
  public function getFormat(): string;

  /**
   * Get the prop name.
   *
   * This is the machine name of the prop.
   *
   * @return string
   *   The prop name.
   */
  public function getName(): string;

  /**
   * Get the prop title.
   *
   * This is the user-facing title of the prop.
   *
   * @return string
   *   The prop title.
   */
  public function getTitle(): string|MarkupInterface;

  /**
   * Get the prop description.
   *
   * This is the user-facing description of the prop.
   *
   * @return string
   *   The prop description.
   */
  public function getDescription(): string|MarkupInterface;

  /**
   * Get the prop properties.
   *
   * This is the properties of the prop.
   *
   * @return array
   *   The prop properties.
   */
  public function getProperties(): array;

  /**
   * Gets the scope of the component shape.
   *
   * @return string
   *   The scope of the component shape.
   */
  public function getScope(): string;

  /**
   * Checks if the component is new.
   *
   * @return bool
   *   TRUE if the component is new, FALSE otherwise.
   */
  public function isNew(): bool;

  /**
   * Converts the component shape structure to a string expression.
   *
   * This method takes the structure of the component shape and converts it
   * into a string expression where each key-value pair is concatenated with
   * a period (.) and each pair is separated by a colon (:).
   *
   * @return string
   *   The string expression representing the component shape structure.
   */
  public function getExpression(): string;

  /**
   * Retrieves the structure of the component shape.
   *
   * This method constructs an array representing the structure of the component
   * shape. It includes the nested ID and reference of the current shape. If the
   * current shape implements the ComponentShapeChildrenPluginInterface, it also
   * merges the structures of its child shapes.
   *
   * @return array
   *   An associative array representing the structure of the component shape.
   */
  public function getStructure(): array;

  /**
   * Retrieves the list of plugin settings.
   *
   * @return array
   *   An array of plugins.
   */
  public function getPlugins(): array;

  /**
   * Checks if the shape has a plugin with the given ID.
   *
   * @param string $pluginId
   *   The ID of the plugin.
   *
   * @return bool
   *   TRUE if the plugin exists, FALSE otherwise.
   */
  public function hasPlugin(string $pluginId): bool;

  /**
   * Sets the plugin with the given ID and settings.
   *
   * This method unsets the current value collection and assigns the provided
   * settings to the plugin identified by the given plugin ID within the nested
   * plugin structure.
   *
   * @param string $pluginId
   *   The ID of the plugin to set.
   * @param array $settings
   *   (optional) An associative array of settings for the plugin. Defaults to
   *   an empty array.
   * @param bool $status
   *   (optional) Whether the plugin is enabled. Defaults to TRUE.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addPlugin(string $pluginId, array $settings = [], bool $status = TRUE): self;

  /**
   * Gets the default plugins for the component shape.
   *
   * @return array
   *   An array of default plugins.
   */
  public function getDefaultPlugins(): array;

  /**
   * Determines if the current shape allows configurable plugins.
   *
   * This means the shape's parent is expanded.
   *
   * @return bool
   *   TRUE if the current shape is an expanded child, FALSE otherwise.
   */
  public function allowConfigurablePlugins(): bool;

  /**
   * Retrieves the collection of component shape plugin values.
   *
   * This method initializes the value collection if it has not been set yet.
   * It gathers the configurations for each plugin by iterating through the
   * filtered definitions from the shape and checking their status and settings.
   * The configurations are then used to create a new
   * ComponentShapePluginCollection instance, which is stored in the
   * valueCollection property.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginCollection
   *   The collection of component shape plugin values.
   */
  public function getValueCollection(): ComponentShapePluginCollection;

  /**
   * Checks if the component is rebuilding.
   *
   * Will be true if the component is being rebuilt without being saved.
   *
   * @return bool
   *   TRUE if the component is rebuilding, FALSE otherwise.
   */
  public function isRebuilding();

  /**
   * Retrieves the root parent shape of the current component shape.
   *
   * If the shape is the root shape, it will return itself.
   *
   * @return ComponentShapePluginInterface
   *   The root parent shape if it exists, or NULL if there is no parent.
   */
  public function getRootShape(): ComponentShapePluginInterface;

  /**
   * Adds a parent shape to the current component shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $parent
   *   The parent shape to be added.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function addParentShape(ComponentShapePluginInterface $parent): self;

  /**
   * Retrieves the parent shapes of the current component shape.
   *
   * The order will be from the first parent to the last parent.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of parent shapes.
   */
  public function getParentShapes(): array;

  /**
   * Retrieves the immediate parent shape of the current component shape.
   *
   * @return ComponentShapePluginInterface|null
   *   The immediate parent shape if it exists, or NULL if there is no parent.
   */
  public function getParentShape(): ?ComponentShapePluginInterface;

  /**
   * Retrieves all expandable shapes recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   *
   * @return array
   *   An array of expandable shapes.
   */
  public function getExpandedableShapes($includeSelf = FALSE): array;

  /**
   * Retrieves all shapes that allow plugins recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   *
   * @return array
   *   An array of expanded children shapes.
   */
  public function getPluginShapes($includeSelf = FALSE): array;

  /**
   * Retrieves all child shapes recursively.
   *
   * This method collects all child shapes, including nested child shapes,
   * and returns them in a sorted array.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   * @param bool $includeDeltas
   *   Whether to include the delta values in the returned shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An associative array of all child shapes, keyed by their nested IDs.
   */
  public function getAllShapes($includeSelf = FALSE, $includeDeltas = FALSE): array;

  /**
   * Checks if the component shape is the root shape.
   *
   * @return bool
   *   TRUE if the component shape is the root shape, FALSE otherwise.
   */
  public function isRoot(): bool;

  /**
   * Checks if the component shape is nested.
   *
   * @return bool
   *   TRUE if the component shape is nested, FALSE otherwise.
   */
  public function isNested(): bool;

  /**
   * Retrieves the path of parent component names.
   *
   * This method iterates through the list of parent components and collects
   * their names into an array.
   *
   * @param bool $includeRoot
   *   (optional) Whether to include the current component in the path. Defaults
   *   to TRUE.
   *
   * @return array
   *   An array of parent component names.
   */
  public function getNestedPath($includeRoot = TRUE): array;

  /**
   * Retrieves the concatenated title of the current component and its parents.
   *
   * This method constructs a title string by combining the title of the current
   * component with the titles of its parent components. The titles are
   * separated by a colon and a space (": ").
   *
   * @param bool $includeRoot
   *   (optional) Whether to include the current component in the path. Defaults
   *   to TRUE.
   *
   * @return string
   *   The concatenated title string of the current component and its parents.
   */
  public function getNestedTitle($includeRoot = TRUE): string;

  /**
   * Adds a nested value provider to the component.
   *
   * @param string|int $id
   *   The ID of the nested element.
   * @param string $providerId
   *   The ID of the provider.
   * @param array $settings
   *   An array of settings for the provider.
   *
   * @return self
   *   Returns the instance of the class for method chaining.
   */
  public function addNestedValueProvider($id, string $providerId, array $settings): self;

  /**
   * Retrieves the nested value providers.
   *
   * @return array
   *   An array of nested value providers.
   */
  public function getNestedValueProviders(): array;

  /**
   * Adds a nested value provider to the component.
   *
   * @param string|int $id
   *   The ID of the nested element.
   * @param string $providerId
   *   The ID of the provider.
   * @param array $settings
   *   An array of settings for the provider.
   *
   * @return self
   *   Returns the instance of the class for method chaining.
   */
  public function addNestedValueModifier($id, string $providerId, array $settings): self;

  /**
   * Set the array of child shape nested ids that are expaneded.
   *
   * @param array $expanded
   *   An array of child shape nested ids that are expaneded.
   *
   * @return $this
   */
  public function setExpanded(array $expanded): self;

  /**
   * Checks if the component shape supports expansion.
   *
   * This is different than isExpandable as this just checks to see if expansion
   * is possible. The isExpandable method checks if the component has allowed
   * it.
   *
   * @return bool
   *   TRUE if the component shape supports expansion, FALSE otherwise.
   */
  public function supportsExpansion(): bool;

  /**
   * Checks if the component shape is expandable.
   *
   * This method checks to see both that the shape supports expansion AND that
   * the shape has allowed it.
   *
   * @return bool
   *   TRUE if the component shape is expandable, FALSE otherwise.
   */
  public function isExpandable(): bool;

  /**
   * Get the array of child shape nested ids that are expaneded.
   *
   * Expanded settings are stored on the root parent shape unless this shape
   * is not expanded, which means it is the root and has the expanded settings.
   *
   * @return array
   *   An array of child shape nested ids that are expaneded.
   */
  public function getExpanded(): array;

  /**
   * Checks if the component shape is expanded.
   *
   * This method determines if the current instance implements the
   * ComponentShapeExpandedPluginInterface, allows expansion, and if the
   * nested ID of the component is in the list of expanded components.
   *
   * @return bool
   *   TRUE if the component shape is expanded, FALSE otherwise.
   */
  public function isExpanded(): bool;

  /**
   * Checks if the component shape belongs to an expanded component.
   *
   * This method checks if the current instance is a child of an expanded
   * component shape or is itself expanded.
   *
   * @return bool
   *   TRUE if the component shape belongs to an expanded component, FALSE
   *   otherwise.
   */
  public function belongsToExpanded(): bool;

  /**
   * Retrieves the nested value modifiers.
   *
   * @return array
   *   An array of nested value modifiers.
   */
  public function getNestedValueModifiers(): array;

  /**
   * Sets the active status of the component shape.
   *
   * @param bool $active
   *   (optional) Whether the component shape is active. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function setActive(bool $active = TRUE): self;

  /**
   * Is the prop active.
   *
   * @return bool
   *   Returns TRUE if the prop is active, FALSE otherwise.
   */
  public function isActive(): bool;

  /**
   * Enforces that the component shape is required.
   *
   * This method sets the `enforceRequired` and `required` properties to TRUE,
   * ensuring that the component shape is marked as required.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function enforceRequired(): self;

  /**
   * Checks if the required enforcement is enabled.
   *
   * @return bool
   *   TRUE if the required enforcement is enabled, FALSE otherwise.
   */
  public function isEnforcedRequired(): bool;

  /**
   * Sets the required status of the component shape.
   *
   * @param bool $required
   *   (optional) Whether the component shape is required. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the component shape plugin.
   */
  public function setRequired(bool $required = TRUE): self;

  /**
   * Is the prop required.
   *
   * @return bool
   *   Returns TRUE if the prop is required, FALSE otherwise.
   */
  public function isRequired(): bool;

  /**
   * Sets the editable state of the component.
   *
   * @param bool $editable
   *   (optional) The editable state to set. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setEditable(bool $editable = TRUE): self;

  /**
   * Gets the editable state of the component.
   *
   * @return bool
   *   The editable state.
   */
  public function getEditable(): bool;

  /**
   * Determines if the component shape is editable.
   *
   * This method checks the `editable` property of the current instance and
   * iterates through all allowed value providers to determine if any of them
   * are not editable. If any provider is not editable, the component shape
   * is considered not editable. The iteration stops if a provider indicates
   * that processing should not continue.
   *
   * @return bool
   *   TRUE if the component shape is editable, FALSE otherwise.
   */
  public function isEditable(): bool;

  /**
   * Sets the locked state of the component.
   *
   * @param bool $locked
   *   (optional) The locked state to set. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function enforceLocked(bool $locked = TRUE): self;

  /**
   * Determines if the component shape is locked.
   *
   * This method checks the `locked` property of the current instance and
   * iterates through all allowed value providers to determine if any of them
   * are not locked. If any provider is not locked, the component shape
   * is considered not locked. The iteration stops if a provider indicates
   * that processing should not continue.
   *
   * @return bool
   *   TRUE if the component shape is locked, FALSE otherwise.
   */
  public function isLocked(): bool;

  /**
   * Get the component.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Retrieves the content entity associated with this plugin.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The content entity.
   */
  public function getEntity(): ContentEntityInterface;

  /**
   * Get the entity type.
   *
   * This is the entity type id set on the component. It is not the entity type
   * of the host entity as that entity is dynamically generated even if there is
   * no entity type set on the component.
   *
   * @return string
   *   The entity type.
   */
  public function getTargetEntityType(): ?string;

  /**
   * Get the entity bundle.
   *
   * This is the bundle id set on the component. It is not the bundle
   * of the host entity as that entity is dynamically generated even if there is
   * no bundle set on the component.
   *
   * @return string
   *   The entity bundle.
   */
  public function getTargetEntityBundle(): ?string;

  /**
   * Sets the field type for the component shape.
   *
   * @param string $fieldType
   *   The field type to set.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setFieldType(string $fieldType): self;

  /**
   * Get the field type.
   *
   * @return string
   *   The field type.
   */
  public function getFieldType(): string;

  /**
   * Sets the field storage settings.
   *
   * @param array $settings
   *   An associative array of field storage settings.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setFieldStorageSettings(array $settings): self;

  /**
   * Get the field storage settings.
   *
   * @return array
   *   The field storage settings.
   */
  public function getFieldStorageSettings(): array;

  /**
   * Sets the field instance settings.
   *
   * @param array $settings
   *   An associative array of field instance settings.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setFieldInstanceSettings(array $settings): self;

  /**
   * Get the field instance settings.
   *
   * @return array
   *   The field instance settings.
   */
  public function getFieldInstanceSettings(): array;

  /**
   * Retrieves the field options from the schema.
   *
   * This method checks if the 'enum' key exists in the schema array. If it
   * does, it returns the associated value, which is expected to be an array of
   * options. If the 'enum' key does not exist, it returns NULL.
   *
   * @return array|null
   *   An array of field options if the 'enum' key exists in the schema, or NULL
   *   if the 'enum' key is not present.
   */
  public function getFieldOptions(): ?array;

  /**
   * Retrieves the field item list for the component shape.
   *
   * This method creates a new field item list instance based on the field
   * storage definition and sets the required property. It then clones the
   * current field item and sets it as the sole value of the field item list.
   * If a host entity is available, it sets the context for the field item list
   * with the host entity.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list instance.
   */
  public function getFieldItemList(): FieldItemListInterface;

  /**
   * Get the field item.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The field item.
   */
  public function getFieldItem(): FieldItemInterface;

  /**
   * Get the prop value that will be sent to the component for rendering.
   *
   * This differs from getValue() in that modifications can be done here that
   * are not compatible with SDC.
   *
   * @param \Drupal\Core\Template\Attribute $attributes
   *   The attribute that will be applied to the component wrapper.
   *
   * @return mixed
   *   The prop value.
   */
  public function getPropValue(Attribute $attributes): mixed;

  /**
   * Get the working prop value.
   *
   * This value should be able to be passed to the SDC.
   *
   * @return mixed
   *   The prop value.
   */
  public function getValue(): mixed;

  /**
   * Adapt the value to the SDC format.
   *
   * The incoming value is the value from the field item. The return value
   * should be the value that is passed to the SDC.
   *
   * @param mixed $value
   *   The value to adapt.
   *
   * @return mixed
   *   The adapted value.
   */
  public function adaptValue(mixed $value): mixed;

  /**
   * Get the default defined in the schema.
   *
   * @return mixed
   *   The default value defined in the schema.
   */
  public function getDefaultSchemaValue(): mixed;

  /**
   * Get the default value of the prop.
   *
   * @return mixed
   *   The default value provided by SDC.
   */
  public function getDefaultValue(): mixed;

  /**
   * Get the default value of the field item.
   *
   * @return array|string
   *   The default value of the field item.
   */
  public function getDefaultFieldItemValue(): array;

  /**
   * Sets the override value.
   *
   * @param mixed $value
   *   The value to set as the override.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setOverrideValue(mixed $value): self;

  /**
   * Retrieves the override value.
   *
   * @return mixed
   *   The override value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  public function getOverrideValue(): mixed;

  /**
   * Sets the parent value.
   *
   * @param mixed $value
   *   The value to set as the parent override.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setParentValue(mixed $value): self;

  /**
   * Retrieves the parent override value.
   *
   * @return mixed
   *   The parent value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  public function getParentValue(): mixed;

  /**
   * Checks if the component shape is empty.
   *
   * @return bool
   *   TRUE if the component shape is empty, FALSE otherwise.
   */
  public function isEmpty(): bool;

  /**
   * Get the field item value.
   *
   * @return mixed
   *   The field item value.
   */
  public function getFieldItemValue(): array;

  /**
   * Set the field item value.
   *
   * @param mixed $value
   *   The field item value.
   *
   * @return $this
   */
  public function setFieldItemValue(mixed $value): self;

  /**
   * Set the widget type.
   *
   * @param string $widgetType
   *   The widget type.
   * @param array $widgetSettings
   *   The widget settings.
   *
   * @return $this
   */
  public function setWidget(string $widgetType, array $widgetSettings = []): self;

  /**
   * Get the widget.
   *
   * @return \Drupal\Core\Field\WidgetInterface|null
   *   The widget.
   */
  public function getWidget(): ?WidgetInterface;

  /**
   * Sets a widget setting.
   *
   * @param string $key
   *   The key of the setting to set.
   * @param mixed $value
   *   The value to set for the specified key.
   *
   * @return self
   *   The current instance for method chaining.
   */
  public function setWidgetSetting(string $key, mixed $value): self;

  /**
   * Sets the widget settings.
   *
   * @param array $settings
   *   An associative array of widget settings.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setWidgetSettings(array $settings): self;

  /**
   * Get the widget type options.
   *
   * @return string[]
   *   The widget type options.
   */
  public function getWidgetTypeOptions(): array;

  /**
   * Get the prop form.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return array|null
   *   The prop form.
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array;

  /**
   * Validate the prop form.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   * @param array $values
   *   The form values.
   */
  public function validateForm(array $form, FormStateInterface $form_state, array $values): void;

  /**
   * Massage the form values.
   *
   * @param array $values
   *   The form values.
   * @param array $original_values
   *   The original values.
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return array|null
   *   The massaged form values.
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array;

  /**
   * Retrieves the 'empty' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'empty' option of the component shape plugin.
   */
  public function getOptionEmpty(): ComponentShapeOption;

  /**
   * Retrieves the 'default' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'default' option of the component shape plugin.
   */
  public function getOptionDefault(): ComponentShapeOption;

  /**
   * Retrieves the 'access' option from the component shape plugin options.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeOption
   *   The 'access' option of the component shape plugin.
   */
  public function getOptionAccess(): ComponentShapeOption;

  /**
   * Sets the default options for a given nested ID.
   *
   * These options will be used if no nested options are set for the shape.
   *
   * @param array $options
   *   An associative array of options to set for the nested component shape.
   * @param string $id
   *   The identifier for the nested component shape.
   *
   * @return $this
   */
  public function setDefaultOptions(array $options, ?string $id = NULL): self;

  /**
   * Sets default nested options for the component shape.
   *
   * This method sets the nested options for the current component shape. If the
   * current shape is the root shape, it merges the provided options with the
   * existing nested options. If the current shape is not the root shape, it
   * delegates the setting of nested options to the root parent shape.
   *
   * The options add to the previously set options. Already set options
   * cannot be overwritten.
   *
   * @param array $options
   *   An associative array of options to set.
   *
   * @return $this
   *   The current instance of the component shape with updated nested options.
   */
  public function setDefaultNestedOptions(array $options): self;

  /**
   * Sets a default nested option for a given name and option name.
   *
   * @param string $name
   *   The name of the nested option.
   * @param string $optionName
   *   The name of the option to set.
   * @param bool $value
   *   The value of the option.
   * @param bool $prependCurrentId
   *   Whether to prepend the current ID to the nested option name.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setDefaultNestedOption(string $name, string $optionName, bool $value = TRUE, bool $prependCurrentId = TRUE): self;

  /**
   * Sets the default 'empty' nested option.
   *
   * @param string $name
   *   The name of the nested option.
   * @param bool $value
   *   The value to set for the 'empty' option.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setDefaultNestedOptionEmpty(string $name, bool $value = TRUE): self;

  /**
   * Sets the default 'default' nested option.
   *
   * @param string $name
   *   The name of the nested option.
   * @param bool $value
   *   The value to set for the 'default' option.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setDefaultNestedOptionDefault(string $name, bool $value = TRUE): self;

  /**
   * Sets the options for a given nested ID.
   *
   * This method sets the options for a nested component shape. If the current
   * shape is the root, it directly sets the options in the nestedOptions array.
   * Otherwise, it delegates the setting of options to the root parent shape.
   *
   * @param array $options
   *   An associative array of options to set for the nested component shape.
   * @param string $id
   *   The identifier for the nested component shape.
   *
   * @return self
   *   Returns the current instance for method chaining.
   */
  public function setOptions(array $options, ?string $id = NULL): self;

  /**
   * Retrieves the options for a given nested ID.
   *
   * This method retrieves the options for a nested component shape. If the
   * current shape is the root, it directly retrieves the options from the
   * nestedOptions array. Otherwise, it delegates the retrieval of options to
   * the root parent shape.
   *
   * @param string $id
   *   The identifier for the nested component shape.
   *
   * @return array
   *   An associative array of options for the nested component shape.
   */
  public function getOptions(?string $id = NULL): array;

  /**
   * Sets nested options for the component shape.
   *
   * This method sets the nested options for the current component shape. If the
   * current shape is the root shape, it merges the provided options with the
   * existing nested options. If the current shape is not the root shape, it
   * delegates the setting of nested options to the root parent shape.
   *
   * The options add to the previously set options. Already set options
   * cannot be overwritten.
   *
   * @param array $options
   *   An associative array of options to set.
   *
   * @return $this
   *   The current instance of the component shape with updated nested options.
   */
  public function setNestedOptions(array $options): self;

  /**
   * Retrieves the nested options for the current shape.
   *
   * This method returns the nested options based on whether the current shape
   * is the root shape or not. If the current shape is the root, it returns its
   * own nested options. Otherwise, it retrieves the nested options from the
   * root parent shape.
   *
   * @return array
   *   An array of nested options.
   */
  public function getNestedOptions(): array;

  /**
   * Sets a nested option for a given nested component shape.
   *
   * @param string $name
   *   The name of the nested component shape.
   * @param string $optionName
   *   The name of the option to set.
   * @param bool $value
   *   The value to set for the option.
   * @param bool $prependCurrentId
   *   Whether to prepend the current shape ID to the nested option name.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setNestedOption(string $name, string $optionName, bool $value = TRUE, bool $prependCurrentId = TRUE): self;

  /**
   * Sets a nested option to mark it as empty.
   *
   * @param string $name
   *   The name of the nested component shape.
   * @param bool $value
   *   The value to set for the empty option.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setNestedOptionEmpty(string $name, bool $value = TRUE): self;

  /**
   * Sets a nested option to mark it as default.
   *
   * @param string $name
   *   The name of the nested component shape.
   * @param bool $value
   *   The value to set for the default option.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setNestedOptionDefault(string $name, bool $value = TRUE): self;

  /**
   * Sets a nested option to mark its access.
   *
   * @param string $name
   *   The name of the nested component shape.
   * @param bool $value
   *   The value to set for the access option.
   *
   * @return $this
   *   The current instance of the component shape.
   */
  public function setNestedOptionAccess(string $name, bool $value = FALSE): self;

  /**
   * Checks if the field definition is supported by the shape.
   *
   * This differs from the support calls in that if it returns FALSE then
   * no other "supports" calls will be made.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition is supported, FALSE otherwise.
   */
  public function allowFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool;

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool;

  /**
   * Check if all field properties are supported.
   *
   * Returning TRUE means that all requirements of the shape are met by the
   * properties of this field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $entityFieldProperties
   *   An array of field properties keyed by name.
   *
   * @return bool
   *   Returns TRUE if ALL field properties are supported, FALSE otherwise.
   */
  public function supportsFieldProperties(FieldDefinitionInterface $entityFieldDefinition, array $entityFieldProperties): bool;

  /**
   * Checks if the given entity field property is supported by the shape.
   *
   * This method determines if the shape can support the provided entity field
   * property. It first retrieves the shape's field properties and checks if
   * there is more than one property. If there is more than one property, it
   * returns FALSE, indicating that the shape cannot be matched by a single
   * property. If there is only one property, it iterates through the shape's
   * field properties and checks if the shape field property supports the given
   * entity field property.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $entityFieldProperty
   *   The entity field property to check.
   *
   * @return bool
   *   TRUE if the shape supports the given entity field property, FALSE
   *   otherwise.
   */
  public function supportsFieldProperty(FieldDefinitionInterface $entityFieldDefinition, DataDefinitionInterface $entityFieldProperty): bool;

  /**
   * Provide custom matches for the field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return array
   *   An array of field keys => labels.
   *   For example, you can grant access to a link's option icon by returning
   *   the following:
   *     [
   *      'options:attributes~data-icon' => $this->t('Icon'),
   *     ]
   */
  public function getMatches(FieldDefinitionInterface $entityFieldDefinition);

  /**
   * Retrieves the config shape for the component.
   *
   * The is useful for getting the fully processed shape with its default
   * values without any override values.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentShapePluginInterface
   *   The default shape for the component.
   */
  public function getConfigShape(): ComponentShapePluginInterface;

  /**
   * Check if shape is scalar.
   *
   * @return bool
   *   Returns TRUE if the shape is scalar, FALSE otherwise.
   */
  public function isScalar(): bool;

  /**
   * Check if shape is iterable.
   *
   * @return bool
   *   Returns TRUE if the shape is iterable, FALSE otherwise.
   */
  public function isIterable(): bool;

  /**
   * Check if shape is traversable.
   *
   * @return bool
   *   Returns TRUE if the shape is traversable, FALSE otherwise.
   */
  public function isTraversable(): bool;

}
