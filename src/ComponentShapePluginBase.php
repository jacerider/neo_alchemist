<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
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
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for neo_component_shape plugins.
 */
abstract class ComponentShapePluginBase extends PluginBase implements ComponentShapePluginInterface, ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

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
   * Whether the prop is nested.
   *
   * @var bool
   */
  protected bool $nested = FALSE;

  /**
   * Whether the prop is required.
   *
   * @var bool
   */
  protected bool $required = FALSE;

  /**
   * Whether the prop is hidden.
   *
   * @var bool
   */
  protected bool $hidden = TRUE;

  /**
   * Enforce as required. If true, the prop will be required.
   *
   * @var bool
   */
  protected bool $enforceRequired = FALSE;

  /**
   * Whether the prop is editable.
   *
   * @var bool
   */
  protected bool $editable = TRUE;

  /**
   * The field type.
   *
   * @var string|null
   */
  protected ?string $fieldType;

  /**
   * The field storage settings.
   *
   * @var array
   */
  protected ?array $fieldStorageSettings;

  /**
   * The field instance settings.
   *
   * @var array
   */
  protected ?array $fieldInstanceSettings;

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
   * The value modifiers.
   *
   * @var array
   */
  protected $modifiers = [];

  /**
   * The value provider instances.
   *
   * @var Drupal\neo_alchemist\ComponentValueModifierPluginInterface[]
   */
  protected $modifierInstances;

  /**
   * The value modifier definitions.
   *
   * @var array
   */
  protected array $modifierDefinitions;

  /**
   * The field item list.
   *
   * @var \Drupal\Core\Field\FieldItemListInterface
   */
  protected FieldItemListInterface $fieldItemList;

  /**
   * The default value.
   *
   * This is the value that will be used by default.
   *
   * @var mixed
   */
  protected mixed $defaultValue = NULL;

  /**
   * The override value.
   *
   * This is the value that will sit on top of the defeault value and any value
   * providers.
   *
   * @var mixed
   */
  protected mixed $overrideValue = NULL;

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
    protected ComponentValueProviderPluginManager $valueProviderManager,
    protected ComponentValueModifierPluginManager $valueModifierManager
  ) {
    parent::__construct([], $plugin_id, $plugin_definition);
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
      $container->get('plugin.manager.neo_component_value_provider'),
      $container->get('plugin.manager.neo_component_value_modifier')
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
   * {@inheritDoc}
   */
  public function init(): self {
    // Reset the field item list.
    unset($this->fieldItem);
    $this->fieldType = $this->getDefaultFieldType();
    $this->fieldStorageSettings = $this->getDefaultFieldStorageSettings();
    $this->fieldInstanceSettings = $this->getDefaultFieldInstaceSettings();

    // Allow value providers to act on the shape.
    foreach ($this->getAllowedValueProviders('init') as $key => $instance) {
      $instance->onShapeInit();
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    // Create the field item.
    $this->fieldItem = $this->buildFieldItem($this->getFieldType(), $this->getFieldStorageSettings(), $this->getFieldInstanceSettings());
    $value = $this->getDefaultValue();
    // Overlay the field/entity value.
    if (isset($this->overrideValue) && $this->isEditable()) {
      $value = $this->overrideValue;
    }
    // Set the value so providers can use it.
    $this->setFieldItemValue($value);
    foreach ($this->getAllowedValueProviders('value') as $providerId => $instance) {
      $value = $instance->provideOverrideValue($value);
      $this->setFieldItemValue($value);
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    return $this;
  }

  /**
   * Builds a field item with the specified field type and settings.
   *
   * @param string $fieldType
   *   The type of the field to be built.
   * @param array $fieldStorageSettings
   *   (optional) An array of storage settings for the field. Defaults to an
   *   empty array.
   * @param array $fieldInstanceSettings
   *   (optional) An array of instance settings for the field. Defaults to an
   *   empty array.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The built field item.
   */
  protected function buildFieldItem(string $fieldType, $fieldStorageSettings = [], $fieldInstanceSettings = []): FieldItemInterface {
    $fieldDataType = 'field_item:' . $fieldType;
    $fieldItemDefinition = FieldStorageDefinition::create($fieldType)->getItemDefinition();
    assert($fieldItemDefinition instanceof DataDefinition);
    if ($fieldStorageSettings) {
      $fieldItemClass = $fieldItemDefinition->getClass();
      $fieldItemDefinition->setSettings($fieldItemClass::storageSettingsFromConfigData($fieldStorageSettings) + $fieldItemDefinition->getSettings());
    }
    if ($fieldInstanceSettings) {
      $fieldItemClass = $fieldItemDefinition->getClass();
      $fieldItemDefinition->setSettings($fieldItemClass::fieldSettingsFromConfigData($fieldInstanceSettings) + $fieldItemDefinition->getSettings());
    }
    /** @var \Drupal\Core\Field\FieldItemInterface $fieldItem */
    $fieldItem = $this->typedDataManager->createInstance($fieldDataType, [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $fieldItemDefinition,
    ]);
    $fieldItem->setContext(NULL, EntityAdapter::createFromEntity($this->getComponent()->getTargetEntity()));

    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $fieldItem->getFieldDefinition();
    $fieldStorageDefinition
      ->setName($this->getName())
      ->setLabel($this->getTitle());
    return $fieldItem;
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
  public function resetValueProviders(): self {
    $this->providers = [];
    $this->providerInstances = [];
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
      // Reset the continue flag.
      return $provider->allowFurtherProcessing()->allowProcessing($op);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getValueProvider(string $providerId): ?ComponentValueProviderPluginInterface {
    if (!isset($this->getValueProviderDefinitions()[$providerId])) {
      return NULL;
    }
    if (!isset($this->providerInstances[$providerId])) {
      $this->providerInstances[$providerId] = $this->valueProviderManager->createInstance($providerId, [
        'shape' => $this,
        'settings' => $this->providers[$providerId] ?? [],
      ]);
    }
    return $this->providerInstances[$providerId];
  }

  /**
   * {@inheritdoc}
   */
  public function getValueModifierDefinitions(): array {
    if (!isset($this->modifierDefinitions)) {
      $this->modifierDefinitions = $this->valueModifierManager->getFilteredDefinitionsFromShape($this);
    }
    return $this->modifierDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function addValueModifier(string $modifierId, array $settings): self {
    if (isset($this->getValueModifierDefinitions()[$modifierId])) {
      $this->modifiers[$modifierId] = $settings;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isValueModifierEnabled(string $modifierId): bool {
    return isset($this->modifiers[$modifierId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getValueModifiers(): array {
    $modifiers = [];
    foreach ($this->modifiers as $modifierId => $settings) {
      if ($instance = $this->getValueModifier($modifierId)) {
        $modifiers[$modifierId] = $instance;
      }
    }
    return $modifiers;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueModifier(string $modifierId, array $settings = NULL): ?ComponentValueModifierPluginInterface {
    if (!isset($this->getValueModifierDefinitions()[$modifierId])) {
      return NULL;
    }
    if (!isset($this->modifierInstances[$modifierId])) {
      $this->modifierInstances[$modifierId] = $this->valueModifierManager->createInstance($modifierId, [
        'shape' => $this,
        'settings' => $this->modifiers[$modifierId] ?? [],
      ]);
    }
    return $this->modifierInstances[$modifierId];
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
  public function isNested(): bool {
    return $this->nested;
  }

  /**
   * {@inheritDoc}
   */
  public function setNested(bool $nested = TRUE): self {
    $this->nested = $nested;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function enforceRequired(): self {
    $this->enforceRequired = TRUE;
    $this->required = TRUE;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isEnforcedRequired(): bool {
    return $this->enforceRequired;
  }

  /**
   * {@inheritDoc}
   */
  public function setRequired(bool $required = TRUE): self {
    if (!$this->isEnforcedRequired()) {
      $this->required = $required;
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isRequired(): bool {
    return $this->required;
  }

  public function setHidden(bool $hidden = TRUE): self {
    $this->hidden = $hidden;
    return $this;
  }

  public function isHidden(): bool {
    return $this->hidden;
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
  public function setFieldType(string $fieldType): self {
    assert(!isset($this->fieldItem), 'Field item has already been set and the field type can no longer be changed.');
    $this->fieldType = $fieldType;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldType(): string {
    return $this->fieldType ?? $this->getDefaultFieldType();
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
  public function setFieldStorageSettings(array $settings): self {
    assert(!isset($this->fieldItem), 'Field item has already been set and the field storage settings can no longer be changed.');
    $this->fieldStorageSettings = $settings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldStorageSettings(): array {
    return $this->fieldStorageSettings ?? $this->getDefaultFieldStorageSettings();
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
  public function setFieldInstanceSettings(array $settings): self {
    assert(!isset($this->fieldItem), 'Field item has already been set and the field instance settings can no longer be changed.');
    $this->fieldInstanceSettings = $settings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldInstanceSettings(): array {
    return $this->fieldInstanceSettings ?? $this->getDefaultFieldInstaceSettings();
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
    foreach ($this->getValueModifiers() as $instance) {
      $value = $instance->modifyValue($value);
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
  public function getDefaultValue(): mixed {
    if (!isset($this->defaultValue)) {
      $this->defaultValue = $this->schema['examples'] ?? [];
      foreach ($this->getAllowedValueProviders('default') as $providerId => $instance) {
        $this->defaultValue = $instance->provideDefaultValue($this->defaultValue);
        if (!$instance->shouldContinueProcessing()) {
          break;
        }
      }
    }
    return $this->defaultValue;
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
  public function getOverrideValue(): mixed {
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
  public function setFieldItemValue(mixed $value): self {
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
    $this->widgetType = $widgetType;
    $this->widgetSettings = $widgetSettings;
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
      if (!isset($this->getWidgetTypeOptions()[$type])) {
        return NULL;
      }
      $configuration['type'] = $type;
    }
    if ($settings = $this->getWidgetSettings()) {
      $configuration['settings'] = $settings;
    }
    $options = [
      'field_definition' => $fieldStorageDefinition,
      'configuration' => $configuration,
    ];
    return $this->widgetManager->getInstance($options + [
      'prepare' => TRUE,
    ]) ?: NULL;
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
    assert(!empty($form['#parents']), 'Form parents must not be empty.');

    $parents = array_merge($form['#parents'], [$this->getName()]);
    $id = Html::getId('shape-form-' . implode('-', $parents));
    $form += [
      '#type' => 'container',
    ];
    $form['#tree'] = TRUE;
    $form['#parents'] = $parents;
    $form['#id'] = $id;

    $form = $this->form($form, $form_state);
    $form['_options'] = [
      '#type' => 'container',
    ];
    if ($this->getScope() !== 'config' && !$this->isNested()) {
      $form['_options']['default'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Default @label', ['@label' => $this->getTitle()]),
        '#description' => $this->t('If checked, the @label will use the default value.', ['@label' => $this->getTitle()]),
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }
    if ($this->getType() === ComponentShapePluginInterface::OBJECT && !$this->isRequired()) {
      $form['_options']['hide'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hide @label', ['@label' => $this->getTitle()]),
        '#description' => $this->t('If checked, the @label will be hidden.', ['@label' => $this->getTitle()]),
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }

    foreach ($this->getAllowedValueProviders('form') as $instance) {
      $instance->formAlter($form, $form_state);
    }

    return $form;
  }

  /**
   * Get the prop form.
   *
   * This method should be used by extending classes to add additional form
   * elements to the prop form.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return array
   *   The prop form.
   */
  protected function form(array $form, FormStateInterface $form_state): array {
    $widget = $this->getWidget();
    if ($widget) {
      $form['widget'] = [
        '#parents' => array_slice($form['#parents'], 0, -1),
      ];
      $form['widget'] = $widget->form($this->getFieldItemList(), $form['widget'], $form_state);
    }
    return $form;
  }

  /**
   * Ajax callback for refreshing the parent.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -2));
    return $element;
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
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $widget = $this->getWidget();
    // kint('initial values', $values);
    $storedValues = $values;
    if ($widget) {
      $massagedValues = $widget->massageFormValues($values, $form, $form_state);
      $massagedValues = $massagedValues[0] ?? [];
      $fieldItem = clone $this->fieldItem;
      $fieldItem->setValue($massagedValues);
      $fieldItem->preSave();
      $actualValues = $fieldItem->getValue();
      $storedValues = array_intersect_key($actualValues, $fieldItem->getProperties(FALSE));
      foreach ($this->getAllowedValueProviders('formMassage') as $instance) {
        $instance->formValuesAlter($storedValues, $values);
      }
    }
    if ($this->getType() === ComponentShapePluginInterface::OBJECT && !$this->isRequired()) {
      // $storedValues['_hide'] = !empty($form_state->getValue([$this->getName(), '_hide']));
    }
    // kint('stored values', $storedValues);
    // die;
    return $storedValues;
  }

  /**
   * The field definition for support check.
   *
   * By default, this is the field definition of the field item list. Shapes
   * such as the ArrayShape override this method to return the nested field
   * definition.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The field definition.
   */
  protected function getFieldDefinitionForSupportCheck(): FieldDefinitionInterface {
    return $this->getFieldItemList()->getFieldDefinition();
  }

  /**
   * {@inheritDoc}
   */
  public function allowFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool {
    $fieldDefinition = $this->getFieldDefinitionForSupportCheck();
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
    $fieldDefinition = $this->getFieldDefinitionForSupportCheck();
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
      $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
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
    $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
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
    return match ($this->getType()) {
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
