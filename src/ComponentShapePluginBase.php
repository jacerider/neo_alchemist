<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for neo_component_shape plugins.
 */
abstract class ComponentShapePluginBase extends PluginBase implements ComponentShapePluginInterface, ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The field item list.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  protected FieldItemListInterface $field;

  /**
   * The field item.
   *
   * @var \Drupal\Core\Field\FieldItemInterface
   */
  protected FieldItemInterface $fieldItem;

  /**
   * Whether the prop is required.
   *
   * @var bool
   */
  protected bool $required = FALSE;

  /**
   * Whether the prop is editable.
   *
   * @var bool
   */
  protected bool $editable = TRUE;

  /**
   * The widget type.
   *
   * @var string|null
   */
  protected ?string $widgetType;

  /**
   * The widget settings.
   *
   * @var array
   */
  protected array $widgetSettings;

  /**
   * The value providers.
   *
   * @var array
   */
  protected $providers = [];

  /**
   * The value provider instances.
   *
   * @var Drupal\neo_alchemist\ComponentValueProviderPluginInterface[]
   */
  protected $providerInstances;

  /**
   * The value provider definitions.
   *
   * @var array
   */
  protected array $providerDefinitions;

  /**
   * The field item list.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  protected FieldItemListInterface $fieldItemList;

  /**
   * The override value.
   *
   * This the value that will sit on top of the defeault value and any value
   * providers.
   *
   * @var mixed
   */
  protected mixed $overrideValue = NULL;

  /**
   * Whether the field item value is the default value.
   *
   * @var bool
   */
  protected bool $isDefaultValue = TRUE;

  /**
   * Constructs a new ComponentShapePluginBase object.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    protected array $schema,
    protected ComponentInterface $component,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager,
    protected ComponentValueProviderPluginManager $valueProviderManager
  ) {
    parent::__construct([], $plugin_id, $plugin_definition);

    $fieldType = $this->getFieldType();
    $fieldDataType = 'field_item:' . $fieldType;
    $fieldItemDefinition = FieldStorageDefinition::create($fieldType)->getItemDefinition();
    assert($fieldItemDefinition instanceof DataDefinition);
    if ($fieldStorageSettings = $this->getFieldStorageSettings()) {
      $fieldItemClass = $fieldItemDefinition->getClass();
      $fieldItemDefinition->setSettings($fieldItemClass::storageSettingsFromConfigData($fieldStorageSettings) + $fieldItemDefinition->getSettings());
    }
    if ($fieldInstanceSettings = $this->getFieldInstanceSettings()) {
      $fieldItemClass = $fieldItemDefinition->getClass();
      $fieldItemDefinition->setSettings($fieldItemClass::fieldSettingsFromConfigData($fieldInstanceSettings) + $fieldItemDefinition->getSettings());
    }
    /** @var \Drupal\Core\Field\FieldItemInterface $fieldItem */
    $this->fieldItem = $this->typedDataManager->createInstance($fieldDataType, [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $fieldItemDefinition,
    ]);
    $this->fieldItem->setContext(NULL, EntityAdapter::createFromEntity($this->getComponent()->getTargetEntity()));

    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $this->fieldItem->getFieldDefinition();
    $fieldStorageDefinition
      ->setName($this->getName())
      ->setLabel($this->getTitle());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['component'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get('plugin.manager.neo_component_value_provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * Retrieves the supported field property types for the plugin.
   *
   * This method returns an array of supported field property types defined
   * in the plugin definition. If no supported field property types are
   * defined, an empty array is returned.
   *
   * @return array
   *   An array of supported field property types.
   */
  protected function getSupportedFieldPropertyTypes(): array {
    $props = $this->pluginDefinition['supports_field_props'] ?? [];
    $shapeFieldProperties = $this->getFieldItemList()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
    if (count($shapeFieldProperties) === 1) {
      // If shape has only one property, we can use the field property type.
      $props[] = reset($shapeFieldProperties)->getDataType();
    }
    return array_unique($props);
  }

  /**
   * {@inheritdoc}
   */
  public function getValueProviderDefinitions(): array {
    if (!isset($this->providerDefinitions)) {
      $this->providerDefinitions = $this->valueProviderManager->getFilteredDefinitionsFromShape($this);
    }
    return $this->providerDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function addValueProvider(string $providerId, array $settings): self {
    if (isset($this->getValueProviderDefinitions()[$providerId])) {
      $this->providers[$providerId] = $settings;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isValueProviderEnabled(string $providerId): bool {
    return isset($this->providers[$providerId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getValueProviders(): array {
    $providers = [];
    foreach ($this->providers as $providerId => $settings) {
      if ($instance = $this->getValueProvider($providerId)) {
        $providers[$providerId] = $instance;
      }
    }
    return $providers;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedValueProviders(string $op): array {
    return array_filter($this->getValueProviders(), function (ComponentValueProviderPluginInterface $provider) use ($op) {
      return $provider->allowProcessing($op);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getValueProvider(string $providerId, array $settings = NULL): ?ComponentValueProviderPluginInterface {
    if (!isset($this->getValueProviderDefinitions()[$providerId])) {
      return NULL;
    }
    if (!$settings && isset($this->providerInstances[$providerId])) {
      return $this->providerInstances[$providerId];

    }
    $instance = $this->valueProviderManager->createInstance($providerId, [
      'shape' => $this,
      'settings' => $settings ?? $this->providers[$providerId] ?? [],
    ]);
    if (!$settings) {
      $this->providerInstances[$providerId] = $instance;
    }
    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public function getSchema(): array {
    return $this->schema;
  }

  /**
   * {@inheritDoc}
   */
  public function getType(): string {
    return is_array($this->schema['type']) ? $this->schema['type'][0] : $this->schema['type'];
  }

  /**
   * {@inheritDoc}
   */
  public function getRef(): string {
    return $this->schema['ref'] ?? $this->getType();
  }

  /**
   * {@inheritDoc}
   */
  public function getName(): string {
    return $this->schema['name'];
  }

  /**
   * {@inheritDoc}
   */
  public function getTitle(): string {
    return $this->schema['title'] ?? '';
  }

  /**
   * {@inheritDoc}
   */
  public function getDescription(): string {
    return $this->schema['description'] ?? '';
  }

  /**
   * {@inheritDoc}
   */
  public function getScope(): string {
    return $this->component->getScope();
  }

  /**
   * {@inheritDoc}
   */
  public function setRequired(bool $required = TRUE): self {
    $this->required = $required;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isRequired(): bool {
    return $this->required;
  }

  /**
   * {@inheritDoc}
   */
  public function setEditable(bool $editable = TRUE): self {
    $this->editable = $editable;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isEditable(): bool {
    $editable = $this->editable;
    foreach ($this->getAllowedValueProviders('edit') as $providerId => $instance) {
      if (!$instance->isEditable()) {
        $editable = FALSE;
      }
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    return $editable;
  }

  /**
   * {@inheritDoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntity(): ContentEntityInterface {
    return $this->getComponent()->getTargetEntity();
  }

  /**
   * {@inheritDoc}
   */
  public function getTargetEntityType(): string {
    return $this->getComponent()->getTargetEntityTypeId();
  }

  /**
   * {@inheritDoc}
   */
  public function getTargetEntityBundle(): string {
    return $this->getComponent()->getTargetEntityBundle();
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldType(): string {
    return $this->getDefaultFieldType();
  }

  /**
   * Get the default field type.
   *
   * @return string
   *   The field type.
   */
  protected function getDefaultFieldType(): string {
    $fieldType = $this->pluginDefinition['default_field_type'] ?? $this->pluginDefinition['prop'];
    if ($this->getFieldOptions()) {
      $fieldType = $this->pluginDefinition['default_field_type_with_options'] ?? $fieldType;
    }
    return $fieldType;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldStorageSettings(): array {
    return $this->getDefaultFieldStorageSettings();
  }

  /**
   * Get the default field storage settings.
   *
   * @return array
   *   The default field storage settings.
   */
  protected function getDefaultFieldStorageSettings(): array {
    $settings = [];
    if ($options = $this->getFieldOptions()) {
      $settings['allowed_values'] = array_map(fn ($v) => [
        'value' => $v,
        'label' => ucwords(str_replace(['-', '_'], ' ', (string) $v)),
      ], $options);
    }
    return $settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldInstanceSettings(): array {
    return $this->getDefaultFieldInstaceSettings();
  }

  /**
   * Get the default field instance settings.
   *
   * @return array
   *   The default field instance settings.
   */
  protected function getDefaultFieldInstaceSettings(): array {
    $settings = [];
    if ($min = $this->schema['minimum'] ?? (array_key_exists('exclusiveMinimum', $this->schema) ? $this->schema['exclusiveMinimum'] + 1 : '')) {
      $settings['min'] = $min;
    }
    if ($max = $this->schema['maximum'] ?? (array_key_exists('exclusiveMaximum', $this->schema) ? $this->schema['exclusiveMaximum'] - 1 : '')) {
      $settings['max'] = $max;
    }
    return $settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): ?array {
    if (array_key_exists('enum', $this->schema)) {
      return $this->schema['enum'];
    }
    return NULL;
  }

  /**
   * Retrieves the field item list for the component shape.
   *
   * This method creates a new field item list instance based on the field
   * storage definition and sets the required property. It then gets the
   * current field item and sets it as the sole value of the field item list.
   * If a host entity is available, it sets the context for the field item list
   * with the host entity.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list instance.
   */
  public function getFieldItemList(): FieldItemListInterface {
    if (!isset($this->fieldItemList)) {
      /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
      $fieldStorageDefinition = $this->fieldItem->getFieldDefinition();

      $list_class = $fieldStorageDefinition->getClass();
      $fieldStorageDefinition->setRequired($this->required);
      $field = (new $list_class($fieldStorageDefinition, $fieldStorageDefinition->getName(), NULL));
      $field->set(0, $this->fieldItem);

      // Only *after* the field item list has had its conjured field item set as
      // the sole value, it becomes safe to specify the host entity. Most widgets
      // do not need an entity context, but some do:
      // @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget
      // @see \Drupal\image\Plugin\Field\FieldWidget\ImageWidget
      if ($hostEntity = $this->getEntity()) {
        $field->setContext(NULL, EntityAdapter::createFromEntity($hostEntity));
        assert($fieldStorageDefinition instanceof FieldStorageDefinition);
        $fieldStorageDefinition->setTargetEntityTypeId($hostEntity->getEntityTypeId());
      }
      $this->fieldItemList = $field;
    }
    return $this->fieldItemList;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItem(): FieldItemInterface {
    return $this->fieldItem;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): array|string|int|float|bool {
    $value = $this->denormalizeValue($this->getFieldItemValue());
    if (is_null($value)) {
      return [];
    }
    $value = $this->adaptValue($value);
    if (!empty($this->schema['properties'])) {
      if (!is_array($value)) {
        // If we do not have an array we assume we have an incorrect value.
        $value = $this->getDefaultValue();
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function adaptValue(mixed $value): array|string|int|float|bool {
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultValue(): array|string|int|float|bool|null {
    return $this->schema['examples'] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItemDefaultValue(): array {
    $fieldItem = clone $this->fieldItem;
    $fieldItem->setValue($this->getDefaultValue());
    return $fieldItem->getValue();
  }

  /**
   * Sets the override value.
   *
   * @param mixed $value
   *   The value to set as the override.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setOverrideValue(mixed $value): self {
    $this->overrideValue = $value;
    return $this;
  }

  /**
   * Retrieves the override value.
   *
   * @return array|string|int|float|bool
   *   The override value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  public function getOverrideValue(): array|string|int|float|bool|null {
    return $this->overrideValue;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItemValue(): array {
    return !$this->isFieldItemEmpty() ? $this->fieldItem->getValue() : [];
  }

  /**
   * Checks if the field item is empty.
   *
   * This method determines whether the field item associated with this
   * component is empty or not.
   *
   * Shapes can override this method to provide custom logic for determining
   * whether the field item is empty. This might be necessary if the shape
   * provides non-standard field item values.
   *
   * @return bool
   *   TRUE if the field item is empty, FALSE otherwise.
   */
  protected function isFieldItemEmpty(): bool {
    return $this->fieldItem->isEmpty();
  }

  /**
   * {@inheritDoc}
   */
  public function calculateFieldItemValue(): self {
    // We need a way to process values. Start with schema defaults. Then modify
    // with value providers. Then overlay the user input.
    $this->fieldItem->setValue($this->getDefaultValue());
    foreach ($this->getAllowedValueProviders('calculate') as $providerId => $instance) {
      $instance->onCalculateFieldItemValue();
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    if (isset($this->overrideValue) && $this->isEditable()) {
      $this->fieldItem->setValue($this->overrideValue);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setFieldItemValue(mixed $value): self {
    $this->isDefaultValue = FALSE;
    // If if value is an array but we are not in an array type, we use the first
    // value 0 if set.
    if (is_array($value) && $this->getType() !== 'array') {
      $value = $value[0] ?? $value;
    }
    $this->fieldItem->setValue($value);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setWidget(string $widgetType, array $widgetSettings = []): self {
    if ($this->getWidgetTypeOptions()[$widgetType] ?? NULL) {
      $this->widgetType = $widgetType;
      $this->widgetSettings = $widgetSettings;
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getWidget(): ?WidgetInterface {
    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $this->fieldItem->getFieldDefinition();
    $configuration = [];
    if ($type = $this->getWidgetType()) {
      $configuration['type'] = $type;
    }
    if ($settings = $this->getWidgetSettings()) {
      $configuration['settings'] = $settings;
    }
    return $this->widgetManager->getInstance([
      'field_definition' => $fieldStorageDefinition,
      'configuration' => $configuration,
      'prepare' => TRUE,
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function getWidgetTypeOptions(): array {
    $fieldDefinition = $this->fieldItem->getFieldDefinition();
    $options = $this->widgetManager->getOptions($fieldDefinition->getType());
    $applicable_options = [];
    foreach ($options as $option => $label) {
      $plugin_class = DefaultFactory::getPluginClass($option, $this->widgetManager->getDefinition($option));
      if ($plugin_class::isApplicable($fieldDefinition)) {
        $applicable_options[$option] = $label;
      }
    }
    return $applicable_options;
  }

  /**
   * Get the widget type.
   *
   * If null, the widget will use the default widget for the field type.
   *
   * @return string|null
   *   The widget type.
   */
  protected function getWidgetType(): ?string {
    if (!isset($this->widgetType)) {
      $this->widgetType = $this->getDefaultWidgetType();
    }
    return $this->widgetType;
  }

  /**
   * Get the default widget type.
   *
   * If null, the widget will use the default widget for the field type.
   *
   * @return string|null
   *   The default widget type.
   */
  protected function getDefaultWidgetType(): ?string {
    $widgetType = $this->pluginDefinition['default_field_widget'] ?? NULL;
    if ($this->getFieldOptions()) {
      $widgetType = $this->pluginDefinition['default_field_widget_with_options'] ?? $widgetType;
    }
    return $widgetType;
  }

  /**
   * Get the widget settings.
   *
   * @return array
   *   The widget settings.
   */
  protected function getWidgetSettings(): array {
    if (!isset($this->widgetSettings)) {
      $this->widgetSettings = $this->getDefaultWidgetSettings();
    }
    return $this->widgetSettings;
  }

  /**
   * Get the default widget settings.
   *
   * @return array
   *   The default widget settings.
   */
  protected function getDefaultWidgetSettings(): array {
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function getForm(array $form, FormStateInterface $form_state): ?array {
    $elements = [];
    $widget = $this->getWidget();
    if (!$widget) {
      return $elements;
    }
    $field = $this->getFieldItemList();
    $elements['#parents'] = $form['#parents'] ?? [];
    $widgetForm = $widget->form($field, $elements, $form_state);
    foreach ($this->getAllowedValueProviders('form') as $instance) {
      $instance->widgetFormAlter($widgetForm, $form_state);
    }
    return $widgetForm;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state, array $values): void {
    if (empty($values) && $this->isRequired()) {
      $form_state->setError($form, $this->getTitle() . ' is required.');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $form, FormStateInterface $form_state, array $values): array {
    $massagedValues = $this->getWidget()->massageFormValues($values, $form, $form_state);
    $massagedValues = $massagedValues[0] ?? [];
    $fieldItem = clone $this->fieldItem;
    $fieldItem->setValue($massagedValues);
    $fieldItem->preSave();
    $actualValues = $fieldItem->getValue();
    $storedValues = array_intersect_key($actualValues, $fieldItem->getProperties(FALSE));
    return $storedValues;
  }

  /**
   * {@inheritDoc}
   */
  public function allowFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    $fieldDefinition = $this->getFieldItemList()->getFieldDefinition();
    $fieldStorageSettings = $fieldDefinition->getFieldStorageDefinition()->getSettings();
    $entityFieldStorageSettings = $entityFieldDefinition->getFieldStorageDefinition()->getSettings();
    // If we have allowed values (enum), we need to check if they match.
    if (isset($fieldStorageSettings['allowed_values'])) {
      if (count(array_intersect_key($fieldStorageSettings['allowed_values'] ?? [], $entityFieldStorageSettings['allowed_values'] ?? [])) !== count($fieldStorageSettings['allowed_values'])) {
        return FALSE;
      }
    }
    // Max length.
    if (isset($fieldStorageSettings['max_length']) && $fieldStorageSettings['max_length'] < ($entityFieldStorageSettings['max_length'] ?? 0)) {
      return FALSE;
    }

    // Perform settings comparison.
    $shapeFieldSettings = $fieldDefinition->getSettings();
    $fieldSettings = $entityFieldDefinition->getSettings();
    if (!empty($shapeFieldSettings['min']) && (int) $shapeFieldSettings['min'] > (int) ($fieldSettings['min'] ?? 0)) {
      return FALSE;
    }
    if (!empty($shapeFieldSettings['max']) && (int) $shapeFieldSettings['max'] < (int) ($fieldSettings['max'] ?? 0)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    $fieldDefinition = $this->getFieldItemList()->getFieldDefinition();
    if ($fieldDefinition->getType() === $entityFieldDefinition->getType()) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function supportsFieldProperties(array $entityFieldProperties): bool {
    if (count($entityFieldProperties) === 1) {
      $shapeFieldProperties = $this->getFieldItemList()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
      if (count($shapeFieldProperties) === 1) {
        // When both the shape and the field have only one property, we can
        // match them directly.
        return $this->supportsShapeFieldProperty(reset($shapeFieldProperties), reset($entityFieldProperties));
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function supportsFieldProperty(DataDefinitionInterface $entityFieldProperty): bool {
    $shapeFieldProperties = $this->getFieldItemList()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
    if (count($shapeFieldProperties) > 1) {
      // This shape has more than one property and cannot by matched by a single
      // property.
      return FALSE;
    }
    foreach ($shapeFieldProperties as $shapeFieldPropertyName => $shapeFieldProperty) {
      if ($this->supportsShapeFieldProperty($shapeFieldProperty, $entityFieldProperty)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if a single field property is supported.
   *
   * Returning TRUE means that the requirement of the shape is met by a single
   * property of this field.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $shapeFieldProperty
   *   A shape field property.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $entityFieldProperty
   *   The field property.
   *
   * @return bool
   *   Returns TRUE if the field property is supported, FALSE otherwise.
   */
  protected function supportsShapeFieldProperty(DataDefinitionInterface $shapeFieldProperty, DataDefinitionInterface $entityFieldProperty): bool {
    return in_array($entityFieldProperty->getDataType(), $this->getSupportedFieldPropertyTypes());
  }

  /**
   * Omits the wrapping main property name for single-property field types.
   *
   * This reduces the verbosity of the data stored in `component_tree` fields,
   * which improves both space requirements and the developer experience.
   *
   * @param array<string, mixed> $field_item_value
   *   The value for this static prop source's field item, with field property
   *   names as keys.
   *
   * @return mixed|array<string, mixed>
   *   The denormalized (simplified) value.
   *
   * @see \Drupal\Core\Field\FieldItemBase::setValue()
   *  @see \Drupal\Core\Field\FieldInputValueNormalizerTrait::normalizeValue()
   */
  protected function denormalizeValue(array $field_item_value): mixed {
    return match (count($this->fieldItem->getDataDefinition()->getPropertyDefinitions())) {
      1 => $field_item_value[$this->fieldItem::mainPropertyName()] ?? NULL,
      default => $field_item_value,
    };
  }

  /**
   * Check if shape is scalar.
   *
   * @return bool
   *   Returns TRUE if the shape is scalar, FALSE otherwise.
   */
  public function isScalar(): bool {
    return match ($this) {
      // A subset of the "primitive types" in JSON schema are:
      // - "scalar values" in PHP terminology
      // - "primitives" in Drupal Typed data terminology.
      // @see https://www.php.net/manual/en/function.is-scalar.php
      // @see \Drupal\Core\TypedData\PrimitiveInterface
      self::STRING, self::NUMBER, self::INTEGER, self::BOOLEAN => TRUE,
      // Another subset of the "primitive types" in JSON schema are:
      // - "non-scalar values" in PHP terminology, specifically "iterable"
      // - "traversable" in Drupal Typed Data terminology, specifically "lists"
      //   ("sequences" in config schema) or "complex data" ("mappings" in
      //   config schema)
      // @see https://www.php.net/manual/en/function.is-iterable.php
      // @see \Drupal\Core\TypedData\ListInterface
      // @see \Drupal\Core\TypedData\ComplexDataInterface
      // @see \Drupal\Core\TypedData\TraversableTypedDataInterface
      self::ARRAY, self::OBJECT => FALSE,
    };
  }

  /**
   * Check if shape is iterable.
   *
   * @return bool
   *   Returns TRUE if the shape is iterable, FALSE otherwise.
   */
  public function isIterable(): bool {
    return !$this->isScalar();
  }

  /**
   * Check if shape is traversable.
   *
   * @return bool
   *   Returns TRUE if the shape is traversable, FALSE otherwise.
   */
  public function isTraversable(): bool {
    return !$this->isScalar();
  }

  /**
   * {@inheritDoc}
   */
  public function __clone() {
    $this->fieldItem = clone $this->fieldItem;
  }

}
