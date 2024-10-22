<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements;

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
   * Get expression.
   *
   * @return \Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression
   *   The expression.
   */
  public function getExpression(): ComponentPropExpression;

  /**
   * Get the schema.
   *
   * @return array
   *   The schema.
   */
  public function getSchema(): array;

  /**
   * Get the schema type.
   *
   * @return \Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType
   *   The schema type.
   */
  public function getSchemaType(): SdcPropJsonSchemaType;

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
   * Is the prop required.
   *
   * @return bool
   *   Returns TRUE if the prop is required, FALSE otherwise.
   */
  public function isRequired(): bool;

  /**
   * Set the prop entity type and (optional) bundle.
   *
   * @param string $entityType
   *   The entity type.
   * @param string $entityBundle
   *   (optional) The entity bundle.
   *
   * @return $this
   */
  public function setEntityType(string $entityType, ?string $entityBundle = ''): self;

  /**
   * Get the entity type.
   *
   * @return string|null
   *   The entity type.
   */
  public function getEntityType(): ?string;

  /**
   * Get the entity bundle.
   *
   * @return string|null
   *   The entity bundle.
   */
  public function getEntityBundle(): ?string;

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

  /**
   * Cast to requirements.
   *
   * @return \Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement|\Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements|false
   *   The requirements.
   */
  public function toRequirements(): DataTypeShapeRequirement|DataTypeShapeRequirements|false;

}
