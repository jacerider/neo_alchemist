<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapePluginInterface {

  const STRING = 'string';
  const NUMBER = 'number';
  const INTEGER = 'integer';
  const OBJECT = 'object';
  const ARRAY = 'array';
  const BOOLEAN = 'boolean';

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Retrieves the value provider definitions for the current shape.
   *
   * This method uses the value provider manager to get the filtered definitions
   * based on the current shape instance.
   *
   * @return array
   *   An array of value provider definitions.
   */
  public function getValueProviderDefinitions(): array;

  /**
   * Adds a value provider to the component shape.
   *
   * @param string $providerId
   *   The unique identifier for the provider.
   * @param array $settings
   *   An associative array of settings for the provider.
   *
   * @return self
   *   The current instance of the component shape plugin.
   */
  public function addValueProvider(string $providerId, array $settings): self;

  /**
   * Checks if a value provider is enabled.
   *
   * @param string $providerId
   *   The ID of the provider to check.
   *
   * @return bool
   *   TRUE if the provider is enabled, FALSE otherwise.
   */
  public function isValueProviderEnabled(string $providerId): bool;

  /**
   * Retrieves the value providers.
   *
   * @return \Drupal\neo_alchemist\ComponentValueProviderPluginInterface[]
   *   An array of value providers.
   */
  public function getValueProviders(): array;

  /**
   * Retrieves the allowed value providers.
   *
   * This method filters the value providers to return only those that allow
   * processing.
   *
   * @param string $op
   *   The operation to filter the value providers by.
   *
   * @return array
   *   An array of allowed value providers.
   */
  public function getAllowedValueProviders(string $op): array;

  /**
   * Retrieves a value provider instance based on the given provider ID.
   *
   * @param string $providerId
   *   The ID of the value provider to create.
   *
   * @return \Drupal\neo_alchemist\ComponentValueProviderPluginInterface|null
   *   The value provider instance.
   */
  public function getValueProvider(string $providerId): ?ComponentValueProviderPluginInterface;

  /**
   * Retrieves the value modifier definitions for the current shape.
   *
   * This method uses the value modifier manager to get the filtered definitions
   * based on the current shape instance.
   *
   * @return array
   *   An array of value modifier definitions.
   */
  public function getValueModifierDefinitions(): array;

  /**
   * Adds a value modifier to the component shape.
   *
   * @param string $modifierId
   *   The unique identifier for the modifier.
   * @param array $settings
   *   An associative array of settings for the modifier.
   *
   * @return self
   *   The current instance of the component shape plugin.
   */
  public function addValueModifier(string $modifierId, array $settings): self;

  /**
   * Checks if a value modifier is enabled.
   *
   * @param string $modifierId
   *   The ID of the modifier to check.
   *
   * @return bool
   *   TRUE if the modifier is enabled, FALSE otherwise.
   */
  public function isValueModifierEnabled(string $modifierId): bool;

  /**
   * Retrieves the value modifiers.
   *
   * @return \Drupal\neo_alchemist\ComponentValueModifierPluginInterface[]
   *   An array of value modifiers.
   */
  public function getValueModifiers(): array;

  /**
   * Retrieves a value modifier instance based on the given modifier ID.
   *
   * @param string $modifierId
   *   The ID of the value modifier to create.
   *
   * @return \Drupal\neo_alchemist\ComponentValueModifierPluginInterface|null
   *   The value modifier instance.
   */
  public function getValueModifier(string $modifierId): ?ComponentValueModifierPluginInterface;

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
   * Gets the scope of the component shape.
   *
   * @return string
   *   The scope of the component shape.
   */
  public function getScope(): string;

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
   * Get the field type.
   *
   * @return string
   *   The field type.
   */
  public function getFieldType(): string;

  /**
   * Get the field storage settings.
   *
   * @return array
   *   The field storage settings.
   */
  public function getFieldStorageSettings(): array;

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
   * @return array|string|int|float|bool
   *   The prop value.
   */
  public function getValue(): array|string|int|float|bool;

  /**
   * Adapt the value to the SDC format.
   *
   * The incoming value is the value from the field item. The return value
   * should be the value that is passed to the SDC.
   *
   * @param mixed $value
   *   The value to adapt.
   *
   * @return array|string|int|float|bool
   *   The adapted value.
   */
  public function adaptValue(mixed $value): array|string|int|float|bool;

  /**
   * Get the default value of the prop.
   *
   * @return array|string|int|float|bool|null
   *   The default value provided by SDC.
   */
  public function getDefaultValue(): array|string|int|float|bool|null;

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
   * @return array|string|int|float|bool
   *   The override value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  public function getOverrideValue(): array|string|int|float|bool|null;

  /**
   * Get the field item value.
   *
   * @return mixed
   *   The field item value.
   */
  public function getFieldItemValue(): array;

  /**
   * Calculates the value of the field item.
   *
   * This method processes the field item value by starting with the schema
   * defaults, then modifying with value providers, and finally overlaying
   * the user input if applicable.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function calculateFieldItemValue(): self;

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
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   * @param array $values
   *   The form values.
   *
   * @return array
   *   The massaged form values.
   */
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array;

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
