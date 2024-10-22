<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for neo_component_shape plugins.
 */
abstract class ComponentShapePluginBase extends PluginBase implements ComponentShapePluginInterface, ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The field item.
   *
   * @var \Drupal\Core\Field\FieldItemInterface
   */
  protected FieldItemInterface $fieldItem;

  /**
   * The schema.
   *
   * @var array
   */
  protected array $schema;

  /**
   * The schema type.
   *
   * @var \Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType
   */
  protected SdcPropJsonSchemaType $schemaType;

  /**
   * Whether the prop is required.
   *
   * @var bool
   */
  protected bool $required;

  /**
   * The widget type.
   *
   * @var string
   */
  protected string $widgetType;

  /**
   * The widget settings.
   *
   * @var array
   */
  protected array $widgetSettings;

  /**
   * The target entity type.
   *
   * @var string
   */
  protected string $entityType;

  /**
   * The target entity bundle.
   *
   * @var string
   */
  protected string $entityBundle;

  /**
   * Constructs a new ComponentShapePluginBase object.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    array $schema,
    bool $required,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager
  ) {
    parent::__construct([], $plugin_id, $plugin_definition);
    $this->schema = $schema;
    $this->schemaType = SdcPropJsonSchemaType::from($this->getType());
    $this->required = $required;

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
    $this->fieldItem = $this->typedDataManager->createInstance($fieldDataType, [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $fieldItemDefinition,
    ]);

    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $this->fieldItem->getFieldDefinition();
    $fieldStorageDefinition
      ->setName($this->getName())
      ->setLabel($this->getTitle());

    $this->setFieldItemValue($this->getDefaultValue());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['required'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget')
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
   * {@inheritdoc}
   */
  public function getExpression(): ComponentPropExpression {
    return new ComponentPropExpression($this->getPluginId(), $this->getName());
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
  public function getSchemaType(): SdcPropJsonSchemaType {
    return $this->schemaType;
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
  public function isRequired(): bool {
    return $this->required;
  }

  /**
   * {@inheritDoc}
   */
  public function setEntityType(string $entityType, ?string $entityBundle = ''): self {
    $this->entityType = $entityType;
    $this->entityBundle = $entityBundle;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityType(): ?string {
    return $this->entityType ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityBundle(): ?string {
    return $this->entityBundle ?? NULL;
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
    return $this->pluginDefinition['prop'];
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
    return [];
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
    return [];
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
   * {@inheritDoc}
   */
  public function getFieldItemValue(): array {
    return $this->fieldItem->getValue();
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
    return NULL;
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

    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $this->fieldItem->getFieldDefinition()->getFieldStorageDefinition();
    $list_class = $fieldStorageDefinition->getClass();
    $fieldStorageDefinition->setRequired($this->required);
    $field = (new $list_class($fieldStorageDefinition, $fieldStorageDefinition->getName(), NULL));

    $fieldItem = clone $this->fieldItem;
    $field->set(0, $fieldItem);

    // Only *after* the field item list has had its conjured field item set as
    // the sole value, it becomes safe to specify the host entity. Most widgets
    // do not need an entity context, but some do:
    // @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget
    // @see \Drupal\image\Plugin\Field\FieldWidget\ImageWidget
    $host_entity = Node::create([
      'type' => 'page',
    ]);
    if ($host_entity) {
      $field->setContext(NULL, EntityAdapter::createFromEntity($host_entity));
      $field_storage_definition = $field->getFieldDefinition()->getFieldStorageDefinition();
      assert($field_storage_definition instanceof FieldStorageDefinition);
      $field_storage_definition->setTargetEntityTypeId($host_entity->getEntityTypeId());
    }

    $elements['#parents'] = $form['#parents'] ?? [];
    return $widget->form($field, $elements, $form_state);
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
    return $this->schemaType->isScalar();
  }

  /**
   * Check if shape is iterable.
   *
   * @return bool
   *   Returns TRUE if the shape is iterable, FALSE otherwise.
   */
  public function isIterable(): bool {
    return $this->schemaType->isIterable();
  }

  /**
   * Check if shape is traversable.
   *
   * @return bool
   *   Returns TRUE if the shape is traversable, FALSE otherwise.
   */
  public function isTraversable(): bool {
    return $this->schemaType->isTraversable();
  }

  /**
   * Cast to requirements.
   *
   * @return \Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement|\Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements|false
   *   The requirements.
   */
  public function toRequirements(): DataTypeShapeRequirement|DataTypeShapeRequirements|false {
    return $this->schemaType->toDataTypeShapeRequirements($this->getSchema());
  }

  /**
   * {@inheritDoc}
   */
  public function __clone() {
    $this->fieldItem = clone $this->fieldItem;
  }

}
