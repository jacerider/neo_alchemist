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
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
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
   * Whether the form should be shown even when the option empty is TRUE.
   *
   * @var bool
   */
  public bool $enforceShowForm = FALSE;

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
   * The nested value providers.
   *
   * @var array
   */
  protected $providersNested = [];

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
   * The nested value modifiers.
   *
   * @var array
   */
  protected $modifiersNested = [];

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
   * The parent shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected array $parents = [];

  /**
   * The options.
   *
   * @var Drupal\neo_alchemist\ComponentShapePluginOption[]
   */
  protected array $options = [];

  /**
   * The nested options.
   *
   * @var array
   */
  protected array $optionsNested = [];

  /**
   * The expanded child shapes.
   *
   * @var array
   */
  protected array $expanded = [];

  /**
   * The all child shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected array $allChildShapes;

  protected array $plugins = [];

  /**
   * The plugin definitions.
   *
   * @var array
   */
  protected array $pluginDefinitions;

  // protected array $pluginInstances = [];

  /**
   * Constructs a new ComponentShapePluginBase object.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    protected array $schema,
    protected array $settings,
    protected ComponentInterface $component,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager,
    protected ComponentValuePluginManagerInterface $valueManager
  ) {
    parent::__construct([], $plugin_id, $plugin_definition);
    // Set default options.
    $this->options['empty'] = new ComponentShapePluginOption(FALSE, FALSE);
    $this->options['default'] = new ComponentShapePluginOption(FALSE, TRUE);
    // Initialize settings.
    $this->setExpanded($settings['expanded'] ?? []);
    $this->setEditable($settings['editable'] ?? TRUE);
    $this->setRequired($settings['required'] ?? FALSE);
    $this->setOptions($settings['options'] ?? [], TRUE);
    $this->setOverrideValue($settings['value'] ?? NULL);
    if (isset($settings['shape']) && $settings['shape'] === $this->getPluginId()) {
      // Only set plugins if the shape has not changed.
      $this->plugins = $settings['plugins'] ?? [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['settings'],
      $configuration['component'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get('plugin.manager.neo_component_value')
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
    // foreach ($this->getAllowedValueProviders('init') as $key => $instance) {
    //   $instance->onShapeInit();
    //   if (!$instance->shouldContinueProcessing()) {
    //     break;
    //   }
    // }
    // Allow plugins to act on the shape during initilization.
    foreach ($this->getAllowedPlugins('init') as $key => $instance) {
      $instance->onShapeInit();
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    // Create the field item.
    $this->fieldItem = $this->buildFieldItem($this->getFieldType(), $this->getFieldStorageSettings(), $this->getFieldInstanceSettings());
    $value = $this->getDefaultValue();
    // Overlay the field/entity value. We avoid this when component is new so
    // that default values will be provided when option default is TRUE.
    if (!$this->isRebuilding() && isset($this->overrideValue)) {
      if (!$this->isOptionDefault()) {
        $value = $this->overrideValue;
      }
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
    // $this->setOptionDefault($this->isNew());
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

    /** @var \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $fieldStorageDefinition */
    $fieldStorageDefinition = $fieldItem->getFieldDefinition();
    $fieldStorageDefinition
      ->setName($this->getName())
      ->setLabel($this->getTitle())
      ->setRequired($this->isRequired());

    // HAVING THIS HERE BREAKS ARRAYS with URLs
    // if ($hostEntity = $this->getEntity()) {
    //   $fieldItem->setContext(NULL, EntityAdapter::createFromEntity($this->getComponent()->getTargetEntity()));
    // }
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
   * {@inheritDoc}
   */
  public function addParentShape(ComponentShapePluginInterface $parent): self {
    $this->parents[] = $parent;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getAllParentShapes($includeSelf = FALSE, $addRefToKey = FALSE): array {
    $shapes = [];
    foreach ($this->parents as $shape) {
      $key = $shape->getNestedId();
      if ($addRefToKey) {
        $key .= ':' . $shape->getRef();
      }
      $shapes[$key] = $shape;
    }
    return $this->parents;
  }

  /**
   * {@inheritDoc}
   */
  public function getDirectParentShape(): ?ComponentShapePluginInterface {
    return end($this->parents) ?: NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getAllExpandedableShapes($includeSelf = FALSE, $addRefToKey = FALSE): array {
    $shapes = [];
    foreach ($this->getAllChildShapes($includeSelf, $addRefToKey) as $nestedId => $shape) {
      if ($shape instanceof ComponentShapeExpandedPluginInterface && $shape->allowExpanded()) {
        $shapes[$nestedId] = $shape;
      }
    }
    return $shapes;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllChildShapes($includeSelf = FALSE, $addRefToKey = FALSE): array {
    if (!isset($this->allChildShapes)) {
      $shapes = [];
      if ($includeSelf) {
        $shapes[$this->getNestedId()] = $this;
      }
      if ($this instanceof ComponentShapeChildrenPluginInterface) {
        foreach ($this->getChildShapes() as $shape) {
          $shapes += $shape->getAllChildShapes(TRUE);
        }
      }
      $this->allChildShapes = $shapes;
    }
    if ($addRefToKey) {
      $shapes = [];
      foreach ($this->allChildShapes as $nestedId => $shape) {
        $shapes[$nestedId . ':' . $shape->getRef()] = $shape;
      }
      return $shapes;
    }

    return $this->allChildShapes;
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
    // asdf
    return array_filter($this->getValueProviders(), function (ComponentValueProviderPluginInterface $provider) use ($op) {
      // Reset the continue flag.
      return $provider->allowFurtherProcessing()->isAllowed($op);
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getValueProvider(string $providerId, bool $checkEnabled = TRUE): ?ComponentValueProviderPluginInterface {
    if (!isset($this->getValueProviderDefinitions()[$providerId])) {
      return NULL;
    }
    if ($checkEnabled && !$this->isValueProviderEnabled($providerId)) {
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
  public function getValueModifier(string $modifierId, bool $checkEnabled = TRUE): ?ComponentValueModifierPluginInterface {
    if (!isset($this->getValueModifierDefinitions()[$modifierId])) {
      return NULL;
    }
    if ($checkEnabled && !$this->isValueModifierEnabled($modifierId)) {
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
  public function getProperties(): array {
    return $this->schema['properties'] ?? [
      $this->getName() => [
        'title' => $this->getTitle(),
        'type' => $this->getType(),
      ],
    ];
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
  public function isNew(): bool {
    return $this->getComponent()->isNew();
  }

  /**
   * {@inheritDoc}
   */
  public function isRebuilding(): bool {
    return $this->getComponent()->isRebuilding();
  }

  /**
   * {@inheritDoc}
   */
  public function getExpression(): string {
    return implode(':', array_map(fn($name, $ref) => "$name.$ref", array_keys($this->getStructure()), $this->getStructure()));
  }

  /**
   * {@inheritDoc}
   */
  public function getStructure(): array {
    $data = array_map(fn($shape) => $shape->getRef(), $this->getAllChildShapes(TRUE));
    ksort($data);
    return $data;
  }

  /**
   * {@inheritDoc}
   */
  public function isNested(): bool {
    return !empty($this->parents);
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedId(): string {
    return implode('~', $this->getNestedPath());
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedIds($includeRoot = TRUE): array {
    $path = [];
    foreach ($this->parents as $parent) {
      $path[] = $parent->getNestedId();
    }
    $path[] = $this->getNestedId();
    if (!$includeRoot) {
      array_shift($path);
    }
    return $path;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedPath($includeRoot = TRUE): array {
    $path = [];
    foreach ($this->parents as $parent) {
      $path[] = $parent->getName();
    }
    $path[] = $this->getName();
    if (!$includeRoot) {
      array_shift($path);
    }
    return $path;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedTitle($includeRoot = TRUE): string {
    $title = [];
    foreach ($this->parents as $parent) {
      $title[] = $parent->getTitle();
    }
    $title[] = $this->getTitle();
    if (!$includeRoot) {
      array_shift($title);
    }
    return implode(': ', $title);
  }

  protected array $pluginCollections = [];

  public function getPlugins(): array {
    return $this->plugins;
  }

  public function setPlugins(array $plugins): self {
    $this->pluginCollections = [];
    $this->plugins = $plugins;
    return $this;
  }

  // public function getPluginCollection(string $pluginType): ?DefaultLazyPluginCollection {
  //   if (!isset($this->pluginCollections[$pluginType])) {
  //     $this->pluginCollections[$pluginType] = NULL;
  //     if ($this->hasPluginManager($pluginType)) {
  //       $configurations = [];
  //       foreach ($this->getPluginDefinitions($pluginType) as $pluginId => $definition) {
  //         $configurations[$pluginId] = $this->plugins[$this->getNestedId()][$pluginType][$pluginId] ?? [
  //           'id' => $pluginId,
  //           'settings' => [],
  //         ];
  //       }
  //       $this->pluginCollections[$pluginType] = new ComponentShapePluginCollection($this, $this->getPluginManager($pluginType), $configurations);
  //     }
  //   }
  //   return $this->pluginCollections[$pluginType];
  // }

  public function getPluginCollections() {
    return ['value' => $this->getValueCollection()];
  }

  /**
   * The value collection.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginCollection
   */
  protected ComponentShapePluginCollection $valueCollection;

  public function getValuePlugin(string $plugin_id = NULL): ComponentShapePluginCollection|ComponentValuePluginInterface {
    if (!isset($this->valueCollection)) {
      // $this->valueCollection = new ComponentShapePluginCollection($this, )
    }
    return $this->valueCollection;
  }

  public function getAllowedPlugins(string $op): array {
    $plugins = [];
    // foreach ($this->getPluginCollections() as $pluginType => $collection) {
    //   if ($collection) {
    //     foreach ($collection->getActiveInstances() as $instance) {
    //       if ($instance->isAllowed($op)) {
    //         $plugins[$instance->getPluginId()] = $instance;
    //       }
    //     }
    //   }
    // }
    return $plugins;
  }

  // /**
  //  *
  //  * @return \Drupal\neo_alchemist\ComponentShapePluginCollection[]
  //  */
  // public function getPluginCollections(): array {
  //   foreach ($this->getPluginManagers() as $pluginType => $pluginManager) {
  //     $this->getPluginCollection($pluginType);
  //   }
  //   return $this->pluginCollections;
  // }

  // public function getPluginManagers() {
  //   return [
  //     'providers' => $this->valueProviderManager,
  //     'modifiers' => $this->valueModifierManager,
  //   ];
  // }

  // public function hasPluginManager(string $pluginType): bool {
  //   return isset($this->getPluginManagers()[$pluginType]);
  // }

  // public function getPluginManager(string $pluginType): ?ComponentValuePluginManagerInterface {
  //   return $this->getPluginManagers()[$pluginType] ?? NULL;
  // }

  // public function getPluginDefinitions(string $pluginType) {
  //   if (!isset($this->pluginDefinitions[$pluginType])) {
  //     $pluginManager = $this->getPluginManager($pluginType);
  //     $this->pluginDefinitions[$pluginType] = $pluginManager ? $pluginManager->getFilteredDefinitionsFromShape($this) : [];
  //   }
  //   return $this->pluginDefinitions[$pluginType];
  // }

  /**
   * {@inheritDoc}
   */
  public function addNestedValueProvider($nestedId, string $providerId, array $settings): self {
    $this->providersNested[$nestedId][$providerId] = $settings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedValueProviders(): array {
    return $this->providersNested;
  }

  /**
   * {@inheritDoc}
   */
  public function addNestedValueModifier($nestedId, string $modifierId, array $settings): self {
    $this->modifiersNested[$nestedId][$modifierId] = $settings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedValueModifiers(): array {
    return $this->modifiersNested;
  }

  /**
   * {@inheritDoc}
   */
  public function setExpanded(array $expanded): self {
    $this->expanded = $expanded;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getExpanded(): array {
    return $this->expanded;
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
   * Force the widget form to be shown even when the option empty is TRUE.
   *
   * @param bool $enforce
   *   Whether to enforce showing the form.
   *
   * @return $this
   */
  public function enforceShowForm($enforce = TRUE): self {
    $this->enforceShowForm = $enforce;
    return $this;
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
      $field = (new $list_class($fieldStorageDefinition, $fieldStorageDefinition->getName(), NULL));
      $field->set(0, $this->fieldItem);

      // Only *after* the field item list has had its conjured field item set
      // as the sole value, it becomes safe to specify the host entity. Most
      // widgets do not need an entity context, but some do:
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
  public function getValue(): mixed {
    // If the value is set to be empty (which will cause it to be hidden), we
    // don't need to do anything else.
    if ($this->isOptionEmpty()) {
      return [];
    }
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
  public function adaptValue(mixed $value): mixed {
    return $value;
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
   * {@inheritDoc}
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

    $isOptionsEmpty = $this->isOptionEmpty();
    $isOptionDefault = $this->isOptionDefault();

    // Restore previous set values when toggling the default options.
    $previousIsOptionDefault = $form_state->get([
      'previous_option_default', $this->getNestedId(),
    ]);
    if ($previousIsOptionDefault === TRUE && $isOptionDefault === FALSE) {
      $value = $form_state->get(['previous_value', $this->getNestedId()]);
      if ($value) {
        $this->setFieldItemValue($value);
      }
    }
    if ($previousIsOptionDefault === FALSE && $isOptionDefault === TRUE) {
      $valueParents = array_merge($form['#parents'], [$this->getName()]);
      array_shift($valueParents);
      $values = $form_state->getValue($valueParents) ?? [];
      $form_state->set(['previous_value', $this->getNestedId()], $values);
    }
    $form_state->set(['previous_option_default', $this->getNestedId()], $this->isOptionDefault());
    // End of previous store.

    $parents = array_merge($form['#parents'], [$this->getName()]);
    $id = Html::getId('shape-form-' . implode('-', $parents));
    $form += [
      '#type' => 'container',
    ];
    $form['#tree'] = TRUE;
    $form['#parents'] = $parents;
    $form['#id'] = $id;

    if (($this->enforceShowForm || !$isOptionsEmpty) && !$isOptionDefault) {
      $form = $this->form($form, $form_state);
    }

    $form['_options'] = [
      '#type' => 'container',
      '#weight' => !empty($form['#title']) ? -10 : 0,
      '#neo_fieldset_region' => 'legend_end',
      '#attributes' => [
        'class' => ['form--inline', 'whitespace-nowrap', 'items-center'],
      ],
    ];
    if ($this->accessOptionDefault()) {
      $form['#type'] = 'fieldset';
      if ($isOptionDefault) {
        $form['#title'] = $this->t('@label (Default)', ['@label' => $this->getTitle()]);
      }

      $form['_options']['value_default'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Default'),
        '#description' => $this->t('Use the default value of @label', ['@label' => $this->getTitle()]),
        '#default_value' => $isOptionDefault,
        '#access' => $this->enforceShowForm || !$isOptionsEmpty,
        '#neo_size' => 'xs',
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }
    if (!$this->enforceShowForm && $this->accessOptionEmpty()) {
      $form['#type'] = 'fieldset';
      if ($isOptionsEmpty) {
        $form['#title'] = $this->t('@label (Hidden)', ['@label' => $this->getTitle()]);
      }
      $form['_options']['value_empty'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hide'),
        '#description' => $this->t('Do not show @label', ['@label' => $this->getTitle()]),
        '#tooltip' => TRUE,
        '#default_value' => $isOptionsEmpty,
        '#access' => !$isOptionDefault,
        '#neo_size' => 'xs',
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }
    $form['_options']['#access'] = !empty(Element::children($form['_options']));

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
    if ($form_state->hasValue(['_options', 'value_empty'])) {
      // Convert to boolean.
      $status = (bool) $form_state->getValue([
        '_options', 'value_empty',
      ]);
      $form_state->setValue(['_options', 'value_empty'], $status);
    }
    if ($form_state->hasValue(['_options', 'value_default'])) {
      // Convert to boolean.
      $status = (bool) $form_state->getValue([
        '_options', 'value_default',
      ]);
      $form_state->setValue(['_options', 'value_default'], $status);
    }
    if (empty($values) && $this->isRequired()) {
      $form_state->setError($form, $this->getTitle() . ' is required.');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): array {
    if ($this->isOptionDefault()) {
      // We marked as default, we continue to store the value so that it can
      // be restored by the user.
      return $original_values;
    }
    // Remove options so that they are not processed or stored.
    unset($values['_options']);
    $widget = $this->getWidget();
    $storedValues = $values;
    if ($widget) {
      $massagedValues = $widget->massageFormValues($values, $form, $form_state);
      $massagedValues = $massagedValues[0] ?? [];
      $fieldItem = clone $this->fieldItem;
      $fieldItem->setValue($massagedValues);
      $fieldItem->preSave();
      $actualValues = $fieldItem->getValue();
      $storedValues = array_intersect_key($actualValues, $fieldItem->getProperties(FALSE));
    }
    return $storedValues;
  }

  /**
   * {@inheritDoc}
   */
  public function setOptionEmptyAccess(bool $value = TRUE): self {
    $this->options['empty']->setAccess($value);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function accessOptionEmpty(): bool {
    return !$this->isRequired() && $this->options['empty']->access();
  }

  /**
   * {@inheritDoc}
   */
  public function setOptionEmpty(bool $value = TRUE, bool $lock = FALSE): self {
    $this->options['empty']->setValue($value, $lock);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isOptionEmpty(): bool {
    return !$this->isRequired() && $this->options['empty']->getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function setOptionDefaultAccess(bool $value = TRUE): self {
    $this->options['default']->setAccess($value);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function accessOptionDefault(): bool {
    if ($this->getNestedId() === 'heading') {
      // We need to consider how we get objects with children to allow empty.
      // ksm($this->isNested());
      // ksm($this->getNestedId());
      // ksm($this->options);
    }
    if ($this->isNested() && $this->getScope() === 'config') {
      return FALSE;
    }
    return $this->options['default']->access();
  }

  /**
   * {@inheritDoc}
   */
  public function setOptionDefault(bool $value = TRUE, bool $lock = FALSE): self {
    $this->options['default']->setValue($value, $lock);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isOptionDefault(): bool {
    return $this->options['default']->getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function setOptions(array $options, bool $lock = FALSE): self {
    if (isset($options['value_empty'])) {
      $this->setOptionEmpty((bool) $options['value_empty'], $lock);
    }
    if (isset($options['value_default'])) {
      $this->setOptionDefault((bool) $options['value_default'], $lock);
    }
    if (isset($options['nested'])) {
      $this->setNestedOptions($options['nested']);
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setNestedOptions(array $options): self {
    if ($this instanceof ComponentShapeChildrenPluginInterface) {
      $this->optionsNested = $options;
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedOptions($nestedId): array {
    return $this->optionsNested[$nestedId] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  protected function getFieldDefinitionForSupportCheck(): FieldDefinitionInterface {
    return $this->getFieldItemList()->getFieldDefinition();
  }

  public function onAdd(): void {
  }

  public function onRemove(): void {
    foreach ($this->getPluginCollections() as $pluginType => $collection) {
      foreach ($collection->getActiveInstances() as $pluginId => $instance) {
        $instance->onPropRemove();
      }
    }
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
   * {@inheritDoc}
   */
  public function getConfigShape(): ComponentShapePluginInterface {
    /** @var \Drupal\neo_alchemist\ComponentInterface $neoComponent */
    $neoComponent = $this->entityTypeManager->getStorage('neo_component')->load($this->getComponent()->id());
    return $neoComponent->getPropShape($this->getName());
  }

  /**
   * {@inheritDoc}
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
   * {@inheritDoc}
   */
  public function isIterable(): bool {
    return !$this->isScalar();
  }

  /**
   * {@inheritDoc}
   */
  public function isTraversable(): bool {
    return !$this->isScalar();
  }

  /**
   * {@inheritDoc}
   */
  public function __clone() {
    unset($this->fieldItem);
    unset($this->fieldItemList);
  }

}
