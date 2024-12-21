<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ObjectWithPluginCollectionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapePluginInterface extends PluginInspectionInterface, DerivativeInspectionInterface, ObjectWithPluginCollectionInterface {

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
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An array of parent shapes.
   */
  public function getAllParentShapes(): array;

  /**
   * Retrieves the direct parent shape of the current component shape.
   *
   * @return ComponentShapePluginInterface|null
   *   The direct parent shape if it exists, or NULL if there is no parent.
   */
  public function getDirectParentShape(): ?ComponentShapePluginInterface;

  /**
   * Retrieves the root parent shape of the current component shape.
   *
   * @return ComponentShapePluginInterface|null
   *   The root parent shape if it exists, or NULL if there is no parent.
   */
  public function getRootParentShape(): ?ComponentShapePluginInterface;

  /**
   * Retrieves all expandable shapes recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   * @param bool $addRefToKey
   *   Whether to add a reference to the key.
   *
   * @return array
   *   An array of expandable shapes.
   */
  public function getAllExpandedableShapes($includeSelf = FALSE, $addRefToKey = FALSE): array;

  /**
   * Retrieves all shapes that allow plugins recursively.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   * @param bool $addRefToKey
   *   Whether to add a reference to the key.
   *
   * @return array
   *   An array of expanded children shapes.
   */
  public function getAllPluginShapes($includeSelf = FALSE, $addRefToKey = FALSE): array;

  /**
   * Retrieves all child shapes recursively.
   *
   * This method collects all child shapes, including nested child shapes,
   * and returns them in a sorted array.
   *
   * @param bool $includeSelf
   *   Whether to include the current shape in the list.
   * @param bool $addRefToKey
   *   Whether to add a reference to the key.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   An associative array of all child shapes, keyed by their nested IDs.
   */
  public function getAllChildShapes($includeSelf = FALSE, $addRefToKey = FALSE): array;

  // /**
  //  * Retrieves the value provider definitions for the current shape.
  //  *
  //  * This method uses the value provider manager to get the filtered definitions
  //  * based on the current shape instance.
  //  *
  //  * @return array
  //  *   An array of value provider definitions.
  //  */
  // public function getValueProviderDefinitions(): array;

  // /**
  //  * Adds a value provider to the component shape.
  //  *
  //  * @param string $providerId
  //  *   The unique identifier for the provider.
  //  * @param array $settings
  //  *   An associative array of settings for the provider.
  //  *
  //  * @return self
  //  *   The current instance of the component shape plugin.
  //  */
  // public function addValueProvider(string $providerId, array $settings): self;

  // /**
  //  * Checks if a value provider is enabled.
  //  *
  //  * @param string $providerId
  //  *   The ID of the provider to check.
  //  *
  //  * @return bool
  //  *   TRUE if the provider is enabled, FALSE otherwise.
  //  */
  // public function isValueProviderEnabled(string $providerId): bool;

  // /**
  //  * Retrieves the value providers.
  //  *
  //  * @return \Drupal\neo_alchemist\ComponentValueProviderPluginInterface[]
  //  *   An array of value providers.
  //  */
  // public function getValueProviders(): array;

  // /**
  //  * Retrieves the allowed value providers.
  //  *
  //  * This method filters the value providers to return only those that allow
  //  * processing.
  //  *
  //  * @param string $op
  //  *   The operation to filter the value providers by.
  //  *
  //  * @return array
  //  *   An array of allowed value providers.
  //  */
  // public function getAllowedValueProviders(string $op): array;

  // /**
  //  * Retrieves a value provider instance based on the given provider ID.
  //  *
  //  * @param string $providerId
  //  *   The ID of the value provider to create.
  //  * @param bool $checkEnabled
  //  *   (optional) Whether to check if the provider is enabled. Defaults to
  //  *   FALSE.
  //  *
  //  * @return \Drupal\neo_alchemist\ComponentValueProviderPluginInterface|null
  //  *   The value provider instance.
  //  */
  // public function getValueProvider(string $providerId, bool $checkEnabled = TRUE): ?ComponentValueProviderPluginInterface;

  // /**
  //  * Retrieves the value modifier definitions for the current shape.
  //  *
  //  * This method uses the value modifier manager to get the filtered definitions
  //  * based on the current shape instance.
  //  *
  //  * @return array
  //  *   An array of value modifier definitions.
  //  */
  // public function getValueModifierDefinitions(): array;

  // /**
  //  * Adds a value modifier to the component shape.
  //  *
  //  * @param string $modifierId
  //  *   The unique identifier for the modifier.
  //  * @param array $settings
  //  *   An associative array of settings for the modifier.
  //  *
  //  * @return self
  //  *   The current instance of the component shape plugin.
  //  */
  // public function addValueModifier(string $modifierId, array $settings): self;

  // /**
  //  * Checks if a value modifier is enabled.
  //  *
  //  * @param string $modifierId
  //  *   The ID of the modifier to check.
  //  *
  //  * @return bool
  //  *   TRUE if the modifier is enabled, FALSE otherwise.
  //  */
  // public function isValueModifierEnabled(string $modifierId): bool;

  // /**
  //  * Retrieves the value modifiers.
  //  *
  //  * @return \Drupal\neo_alchemist\ComponentValueModifierPluginInterface[]
  //  *   An array of value modifiers.
  //  */
  // public function getValueModifiers(): array;

  // /**
  //  * Retrieves a value modifier instance based on the given modifier ID.
  //  *
  //  * @param string $modifierId
  //  *   The ID of the value modifier to create.
  //  * @param bool $checkEnabled
  //  *   (optional) Whether to check if the provider is enabled. Defaults to
  //  *   TRUE.
  //  *
  //  * @return \Drupal\neo_alchemist\ComponentValueModifierPluginInterface|null
  //  *   The value modifier instance.
  //  */
  // public function getValueModifier(string $modifierId, bool $checkEnabled = TRUE): ?ComponentValueModifierPluginInterface;

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
  public function getTitle(): string;

  /**
   * Get the prop description.
   *
   * This is the user-facing description of the prop.
   *
   * @return string
   *   The prop description.
   */
  public function getDescription(): string;

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
  public function setPlugin(string $pluginId, array $settings = [], bool $status = TRUE): self;

  /**
   * Determines if the current shape allows plugins.
   *
   * This means the shape's parent is expanded.
   *
   * @return bool
   *   TRUE if the current shape is an expanded child, FALSE otherwise.
   */
  public function allowPlugins(): bool;

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
   * Retrieves the nested ID by concatenating the elements of the parent path.
   *
   * Each parent ID is separated by a period (.).
   *
   * @return string
   *   The concatenated parent ID.
   */
  public function getNestedId(): string;

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
  public function getNestedIds($includeRoot = TRUE): array;

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
   * @param string|int $nestedId
   *   The ID of the nested element.
   * @param string $providerId
   *   The ID of the provider.
   * @param array $settings
   *   An array of settings for the provider.
   *
   * @return self
   *   Returns the instance of the class for method chaining.
   */
  public function addNestedValueProvider($nestedId, string $providerId, array $settings): self;

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
   * @param string|int $nestedId
   *   The ID of the nested element.
   * @param string $providerId
   *   The ID of the provider.
   * @param array $settings
   *   An array of settings for the provider.
   *
   * @return self
   *   Returns the instance of the class for method chaining.
   */
  public function addNestedValueModifier($nestedId, string $providerId, array $settings): self;

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
   * Checks if the component shape is expandable.
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
   * Retrieves the nested value modifiers.
   *
   * @return array
   *   An array of nested value modifiers.
   */
  public function getNestedValueModifiers(): array;

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
   * Force the widget form to be shown even when the option empty is TRUE.
   *
   * @param bool $enforce
   *   Whether to enforce showing the form.
   *
   * @return $this
   */
  public function enforceShowForm($enforce = TRUE): self;

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
  public function getTargetEntityType(): string;

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
  public function getTargetEntityBundle(): string;

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
   * Get the prop value.
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
  public function getFieldItemDefaultValue(): array;

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
   * @return array
   *   The massaged form values.
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): array;

  /**
   * Sets the access option for empty values.
   *
   * This method allows setting the access control for empty values in the
   * options.
   *
   * @param bool $value
   *   (optional) The access value to set. Defaults to TRUE.
   *
   * @return self
   *   The current instance for method chaining.
   */
  public function setOptionEmptyAccess(bool $value = TRUE): self;

  /**
   * Checks if the 'empty' option is accessible.
   *
   * @return bool
   *   TRUE if the 'empty' option is accessible, FALSE otherwise.
   */
  public function accessOptionEmpty(): bool;

  /**
   * Sets the 'empty' option value.
   *
   * @param bool $value
   *   The value to set for the 'empty' option. Defaults to TRUE.
   * @param bool $lock
   *   Whether to lock the option after setting the value. Defaults to FALSE.
   *
   * @return self
   *   The current instance for method chaining.
   */
  public function setOptionEmpty(bool $value = TRUE, bool $lock = FALSE): self;

  /**
   * Checks if the option is empty.
   *
   * This method determines if the option is considered empty. An option is
   * considered empty if it is not required and its value is empty.
   *
   * @return bool
   *   TRUE if the option is empty, FALSE otherwise.
   */
  public function isOptionEmpty(): bool;

  /**
   * Sets the default access option.
   *
   * This method sets the access value for the default option in the options
   * array.
   *
   * @param bool $value
   *   The access value to set. Defaults to TRUE.
   *
   * @return self
   *   The current instance for method chaining.
   */
  public function setOptionDefaultAccess(bool $value = TRUE): self;

  /**
   * Checks if the default option is accessible.
   *
   * This method returns a boolean indicating whether the default option
   * in the options array has access.
   *
   * @return bool
   *   TRUE if the default option is accessible, FALSE otherwise.
   */
  public function accessOptionDefault(): bool;

  /**
   * Sets the default option value and optionally locks it.
   *
   * @param bool $value
   *   The value to set as the default. Defaults to TRUE.
   * @param bool $lock
   *   Whether to lock the default value. Defaults to FALSE.
   *
   * @return self
   *   The current instance for method chaining.
   */
  public function setOptionDefault(bool $value = TRUE, bool $lock = FALSE): self;

  /**
   * Checks if the option is set to its default value.
   *
   * @return bool
   *   TRUE if the option is set to its default value, FALSE otherwise.
   */
  public function isOptionDefault(): bool;

  /**
   * Sets the options for the component shape.
   *
   * @param array $options
   *   An associative array of options to set. Possible keys:
   *   - value_empty: (bool) Whether the value is empty.
   *   - value_default: (bool) Whether the value is default.
   *   - nested: (array) Nested options to set.
   * @param bool $lock
   *   (optional) Whether to lock the options. Defaults to FALSE.
   *
   * @return self
   *   The current instance of the component shape plugin.
   */
  public function setOptions(array $options, bool $lock = FALSE): self;

  /**
   * Sets the nested options for the component shape.
   *
   * This method sets the nested options for the component shape if the current
   * instance implements the ComponentShapeChildrenPluginInterface.
   *
   * @param array $options
   *   An associative array of options to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setNestedOptions(array $options): self;

  /**
   * Retrieves nested options based on the provided nested ID.
   *
   * @param string|int $nestedId
   *   The ID of the nested options to retrieve.
   *
   * @return array
   *   An array of nested options corresponding to the provided nested ID.
   */
  public function getNestedOptions($nestedId): array;

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
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $entityFieldProperties
   *   An array of field properties keyed by name.
   *
   * @return bool
   *   Returns TRUE if ALL field properties are supported, FALSE otherwise.
   */
  public function supportsFieldProperties(array $entityFieldProperties): bool;

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
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $entityFieldProperty
   *   The entity field property to check.
   *
   * @return bool
   *   TRUE if the shape supports the given entity field property, FALSE
   *   otherwise.
   */
  public function supportsFieldProperty(DataDefinitionInterface $entityFieldProperty): bool;

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
