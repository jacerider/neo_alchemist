<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
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
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use DrupalCodeGenerator\InputOutput\Interviewer;
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
   * Whether the prop is active.
   *
   * This is used to determine if the prop should be processed or not.
   *
   * @var bool
   */
  protected bool $active = TRUE;

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
   * Whether the prop is locked.
   *
   * @var bool
   */
  protected bool $locked;

  /**
   * Enforce as locked.
   *
   * @var bool
   */
  protected bool $enforceLocked = FALSE;

  /**
   * Whether the shape is initialized.
   *
   * @var bool
   */
  protected bool $initialized = FALSE;

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
   * This is the value that will sit on top of the default value and any value
   * providers.
   *
   * @var mixed
   */
  protected mixed $overrideValue = NULL;

  /**
   * The parent value.
   *
   * This is the value that will sit on top of the defeault value and any value
   * providers.
   *
   * @var mixed
   */
  protected mixed $parentValue = NULL;

  /**
   * The parent shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected array $parents = [];

  /**
   * The delta.
   *
   * @var int|null
   */
  protected int|null $delta = NULL;

  /**
   * The options.
   *
   * @var \Drupal\neo_alchemist\ComponentShapeOption[]
   */
  protected array $options = [];

  /**
   * The expanded child shapes.
   *
   * @var array
   */
  protected array $expanded = [];

  /**
   * The widget.
   *
   * @var \Drupal\Core\Field\WidgetInterface|null
   */
  protected WidgetInterface|null $widget;

  /**
   * A cached collection of all child shapes.
   *
   * @var array
   */
  protected array $childShapesAll = [];

  /**
   * The shape plugin settings.
   *
   * @var array
   */
  protected array $plugins = [];

  /**
   * The default nested options.
   *
   * @var array
   */
  protected array $defaultNestedOptions = [];

  /**
   * The nested options.
   *
   * @var array
   */
  protected array $nestedOptions = [];

  /**
   * The default value of the 'empty' option.
   *
   * @var bool
   */
  protected bool $optionEmptyInitValue = FALSE;

  /**
   * The default access of the 'empty' option access.
   *
   * @var bool
   */
  protected bool $optionEmptyInitAccess = TRUE;

  /**
   * The default value of the 'default' option.
   *
   * @var bool
   */
  protected bool $optionDefaultInitValue = FALSE;

  /**
   * The default access of the 'default' option access.
   *
   * @var bool
   */
  protected bool $optionDefaultInitAccess = TRUE;

  /**
   * The cacheable metadata.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cachaeableMetadata;

  /**
   * The value collection.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginCollection
   */
  protected ComponentShapePluginCollection $valueCollection;

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
    protected ComponentValuePluginManagerInterface $valueManager,
  ) {
    parent::__construct([], $plugin_id, $plugin_definition);
    // Set default options.
    $this->options['empty'] = new ComponentShapeOption($this->optionEmptyInitValue, $this->optionEmptyInitAccess);
    $this->options['default'] = new ComponentShapeOption($this->optionDefaultInitValue, $this->optionDefaultInitAccess);
    $this->options['access'] = new ComponentShapeOption(TRUE, FALSE);

    // Only set settings if the shape has not changed.
    if (isset($settings['shape']) && $settings['shape'] === $this->getPluginId()) {
      // Initialize settings.
      $this->setActive($settings['active'] ?? TRUE);
      $this->setExpanded($settings['expanded'] ?? []);
      $this->setEditable($settings['editable'] ?? TRUE);
      $this->setRequired($settings['required'] ?? FALSE);
      $this->setPlugins($settings['plugins'] ?? []);
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
  public function id(): string {
    $id = implode('~', $this->getNestedPath());
    $delta = $this->getDelta();
    if ($delta !== NULL) {
      $id .= "~$delta";
    }
    return $id;
  }

  /**
   * {@inheritDoc}
   */
  public function ids($includeRoot = TRUE): array {
    $ids = array_map(fn($parent) => $parent->id(), $this->parents);
    $ids[] = $this->id();
    if (!$includeRoot) {
      array_shift($ids);
    }
    return $ids;
  }

  /**
   * {@inheritDoc}
   */
  public function getDelta(): ?int {
    return $this->delta;
  }

  /**
   * {@inheritDoc}
   */
  public function setDelta(int $delta): self {
    $this->delta = $delta;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    if (!isset($this->cachaeableMetadata)) {
      $this->cachaeableMetadata = new CacheableMetadata();
    }
    return $this->cachaeableMetadata;
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency($dependency) {
    $this->getCacheableMetadata()->addCacheableDependency($dependency);
    return $this;
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
    // Add default plugins to any shape that doesn't allow plugins.
    if (!$this->allowConfigurablePlugins()) {
      $this->initPlugins();
    }

    // Allow value providers to act on the shape.
    foreach ($this->getValueCollection()->getAllowedInstances('init') as $instance) {
      $instance->onShapeInit();
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }

    // If the shape is required, we do not allow it to be empty.
    if ($this->isRequired()) {
      $this->getOptionEmpty()->setAccess(FALSE, 'Shape is required and cannot be empty.');
    }

    // Initialize the options.
    $this->initOptions();

    // Create the field item.
    $this->fieldItem = $this->buildFieldItem($this->getFieldType(), $this->getFieldStorageSettings(), $this->getFieldInstanceSettings());
    $defaultValue = $this->getDefaultValue();
    $this->setFieldItemValue($defaultValue);

    // Overlay the field/entity value.
    // We first check if the parent value is set. This value comes from
    // parents that are injecting values into their children.
    $overrideValue = $this->getParentValue();
    if (is_null($overrideValue)) {
      // If we have no override value from a parent, we check for an override
      // value that may have been set directly on this shape. This typically
      // comes from user input.
      $overrideValue = $this->getOverrideValue();
      if ($this->getOptionDefault()->isEnabled() || !$this->isEditable()) {
        $overrideValue = NULL;
      }
    }

    $instances = $this->getValueCollection()->getAllowedInstances('value');
    foreach ($instances as $instance) {
      $overrideValue = $instance->provideOverrideValue($overrideValue, $defaultValue);
      if ($overrideValue) {
        $this->setFieldItemValue($overrideValue);
      }
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }

    if (!is_null($overrideValue)) {
      // Allow providers to modify the final override value.
      foreach ($instances as $instance) {
        $overrideValue = $instance->alterValue($overrideValue, 'override');
      }

      $this->setFieldItemValue($overrideValue);
    }

    $this->initialized = TRUE;
    return $this;
  }

  /**
   * Initializes the plugins for the component shape.
   */
  protected function initPlugins() {
    $definition = $this->getPluginDefinition();
    if (!empty($definition['default_plugins'])) {
      foreach ($definition['default_plugins'] as $pluginId => $settings) {
        if (!is_array($settings)) {
          $pluginId = $settings;
          $settings = [];
        }
        $this->addPlugin($pluginId, $settings);
      }
    }
  }

  /**
   * Initializes the options for the component shape.
   *
   * This method retrieves the options for the component shape using its nested
   * ID and sets the values for 'empty' and 'default' options
   * accordingly. If the options are locked, it sets the locked values instead.
   *
   * @return self
   *   Returns the current instance of the class for method chaining.
   */
  protected function initOptions(): self {
    $logMessage = 'Set by initOptions() in shape.';
    if ($options = $this->getOptions($this->id())) {
      foreach (array_keys($this->options) as $optionType) {
        if (isset($options[$optionType])) {
          $option = $this->options[$optionType];
          $option->setValue((bool) $options[$optionType], $logMessage);
        }
      }
    }
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isInitialized(): bool {
    return $this->initialized;
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?? \Drupal::currentUser();
    $access = $this->checkAccess($operation, $account);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Performs access checks.
   *
   * This method is supposed to be overwritten by extending classes that
   * do their own custom access checking.
   *
   * @param string $operation
   *   The entity operation.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkAccess(string $operation, AccountInterface $account): AccessResultInterface {
    return match($operation) {
      'manage_value' => $this->getComponent()->access('update', $account, TRUE),
      'update' => $this->getComponent()->access('update', $account, TRUE)->andIf(AccessResult::allowedIf($this->isEditable())),
      default => AccessResult::allowed(),
    };
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
      ->setDescription($this->getDescription())
      ->setRequired($this->isRequired());

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
  public function onAdd(): void {
    if ($this->allowConfigurablePlugins()) {
      $this->initPlugins();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function onRemove(): void {
  }

  /**
   * {@inheritDoc}
   */
  public function onPluginAdd($pluginId): void {
    if ($this->allowConfigurablePlugins()) {
      $this->getValueCollection()->get($pluginId)->onAdd();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function onPluginRemove($pluginId): void {
    if ($this->allowConfigurablePlugins() && $this->getValueCollection()->has($pluginId)) {
      $this->getValueCollection()->get($pluginId)->onRemove();
    }
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
  public function getFormat(): string {
    return $this->schema['format'] ?? '';
  }

  /**
   * {@inheritDoc}
   */
  public function getTitle(): string|MarkupInterface {
    return $this->schema['title'] ?? 'Unnamed Prop';
  }

  /**
   * {@inheritDoc}
   */
  public function getDescription(): string|MarkupInterface {
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
    $data = array_map(fn($shape) => $shape->getRef(), $this->getAllShapes(TRUE));
    ksort($data);
    return $data;
  }

  /**
   * {@inheritDoc}
   */
  public function getRootShape(): ComponentShapePluginInterface {
    return $this->isRoot() ? $this : reset($this->parents);
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
  public function getParentShape(): ?ComponentShapePluginInterface {
    return end($this->parents) ?: NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getParentShapes($includeSelf = FALSE): array {
    $shapes = [];
    foreach ($this->parents as $shape) {
      $key = $shape->id();
      $shapes[$key] = $shape;
    }
    if ($includeSelf) {
      $shapes[$this->id()] = $this;
    }
    return $shapes;
  }

  /**
   * {@inheritDoc}
   */
  public function getExpandedableShapes($includeSelf = FALSE): array {
    $expanded = $this->getExpanded();
    return array_filter($this->getAllShapes($includeSelf), function ($shape) use ($expanded) {
      $allow = $shape->isExpandable();
      if ($allow) {
        foreach ($expanded as $id) {
          if ($id !== $shape->id() && strpos($id, $shape->id() . '~') !== 0) {
            $allow = FALSE;
            break;
          }
        }
      }
      return $allow;
    });
  }

  /**
   * {@inheritDoc}
   */
  public function getPluginShapes($includeSelf = FALSE): array {
    return array_filter(
      $this->getAllShapes($includeSelf),
      fn($shape) => $shape->allowConfigurablePlugins()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAllShapes($includeSelf = FALSE, $includeDeltas = FALSE): array {
    $key = $includeDeltas ? 'all' : 'structure';
    if (!isset($this->childShapesAll[$key])) {
      $shapes = [];
      if ($this instanceof ComponentShapeChildrenPluginInterface) {
        if ($includeDeltas && $this->isIterable()) {
          // For iterable shapes, process each delta separately.
          $fieldValue = $this->getFieldItemValue();
          foreach (array_filter(array_keys($fieldValue), 'is_int') as $delta) {
            foreach ($this->getChildShapes((int) $delta) as $shape) {
              $shapes += $shape->getAllShapes(TRUE, $includeDeltas);
            }
          }
        }
        else {
          // For non-iterable shapes, process all child shapes at once.
          foreach ($this->getChildShapes() as $shape) {
            $shapes += $shape->getAllShapes(TRUE, $includeDeltas);
          }
        }
      }
      $this->childShapesAll[$key] = $shapes;
    }

    // Use cached shapes if available and not including deltas.
    $shapes = $this->childShapesAll[$key];

    // Add self to the beginning if requested.
    if ($includeSelf) {
      $shapes = [$this->id() => $this] + $shapes;
    }

    return $shapes;
  }

  /**
   * {@inheritDoc}
   */
  public function isRoot(): bool {
    return empty($this->parents);
  }

  /**
   * {@inheritDoc}
   */
  public function isNested(): bool {
    return !$this->isRoot();
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedPath($includeRoot = TRUE): array {
    $path = array_map(fn($parent) => $parent->getName(), $this->parents);
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
    $titles = array_map(fn($parent) => $parent->getTitle(), $this->parents);
    $titles[] = $this->getTitle();
    if (!$includeRoot) {
      array_shift($titles);
    }
    return implode(': ', $titles);
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugins(): array {
    return match($this->isRoot()) {
      TRUE => $this->plugins,
      FALSE => $this->getRootShape()->getPlugins(),
    };
  }

  /**
   * Sets the plugins for the component shape.
   *
   * @param array $plugins
   *   An array of plugins to be set.
   *
   * @return self
   *   Returns the instance of the class for method chaining.
   */
  protected function setPlugins(array $plugins): self {
    $this->plugins = $plugins;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function addPlugin(string $pluginId, array $settings = [], bool $status = TRUE): self {
    $this->getValueCollection()->setInstanceConfiguration($pluginId, [
      'id' => $pluginId,
      'status' => $status,
      'settings' => $settings,
    ]);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function allowConfigurablePlugins(): bool {
    $expanded = $this->getExpanded();
    $isRoot = $this->isRoot();
    if (!$expanded) {
      // If we do not have expanded settings, then we only allow the root
      // shape to have plugins.
      return $isRoot;
    }
    if ($isRoot) {
      return !in_array($this->id(), $expanded);
    }
    // If the shape is expanded, it does not allow plugins.
    if ($this->isExpanded() || $this->isExpandable()) {
      return FALSE;
    }
    $parentShapes = $this->getParentShapes();
    foreach ($parentShapes as $id => $shape) {
      // If shape belongs to a parent that support expansion but does not allow
      // it, we do not allow plugins.
      if ($shape->supportsExpansion() && !$shape->isExpandable()) {
        return FALSE;
      }
    }
    // After we have searched parent shapes for expandable support, we now
    // can assume that any shape that has a parent in the expanded list is
    // expanded.
    foreach ($parentShapes as $id => $shape) {
      if (in_array($id, $expanded)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function getPluginCollections() {
    return ['value' => $this->getValueCollection()];
  }

  /**
   * {@inheritDoc}
   */
  public function getValueCollection(): ComponentShapePluginCollection {
    if (!isset($this->valueCollection)) {
      $configurations = [];
      $plugins = $this->getPlugins();
      foreach ($this->valueManager->getFilteredDefinitionsFromShape($this) as $pluginId => $definition) {
        $configurations[$pluginId] = [
          'id' => $pluginId,
          'status' => !empty($plugins[$this->id()][$pluginId]),
          'settings' => $plugins[$this->id()][$pluginId]['settings'] ?? [],
        ];
      }
      $this->valueCollection = new ComponentShapePluginCollection($this, $this->valueManager, $configurations);
    }
    return $this->valueCollection;
  }

  /**
   * {@inheritDoc}
   */
  public function addNestedValueProvider($id, string $providerId, array $settings): self {
    $this->providersNested[$id][$providerId] = $settings;
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
  public function addNestedValueModifier($id, string $modifierId, array $settings): self {
    $this->modifiersNested[$id][$modifierId] = $settings;
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
    // Expanded settings are stored on the root parent shape unless this shape
    // is not expanded, which means it is the root and has the expanded
    // settings.
    return $this->isRoot() ? $this->expanded : $this->getRootShape()->getExpanded();
  }

  /**
   * {@inheritDoc}
   */
  public function supportsExpansion(): bool {
    return $this instanceof ComponentShapeExpandedPluginInterface;
  }

  /**
   * {@inheritDoc}
   */
  public function isExpandable(): bool {
    return $this instanceof ComponentShapeExpandedPluginInterface && $this->allowExpanded();
  }

  /**
   * {@inheritDoc}
   */
  public function isExpanded(): bool {
    return $this->isExpandable() && in_array($this->id(), $this->getExpanded());
  }

  /**
   * {@inheritDoc}
   */
  public function belongsToExpanded(): bool {
    foreach ($this->getParentShapes() as $shape) {
      if ($shape->isExpanded()) {
        return TRUE;
      }
    }
    return $this->isExpanded();
  }

  /**
   * {@inheritDoc}
   */
  public function setActive(bool $active = TRUE): self {
    $this->active = $active;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isActive(): bool {
    return $this->active;
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
    if ($this->isLocked()) {
      return FALSE;
    }
    return $this->editable;
  }

  /**
   * {@inheritDoc}
   */
  public function enforceLocked(bool $locked = TRUE): self {
    $this->enforceLocked = $locked;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isLocked(): bool {
    if (!isset($this->locked)) {
      $this->locked = $this->enforceLocked;
      if ($this->locked) {
        // If we are already locked, no reason to continue.
        return TRUE;
      }
      foreach ($this->getValueCollection()->getAllowedInstances('edit') as $instance) {
        if (!$instance->isEditable()) {
          $this->locked = TRUE;
        }
        if (!$instance->shouldContinueProcessing()) {
          break;
        }
      }
      // If we are still not locked, check to see if root is locked.
      if (!$this->locked) {
        foreach ($this->getParentShapes() as $shape) {
          if ($shape->isLocked()) {
            $this->locked = TRUE;
          }
        }
      }
    }
    return $this->locked;
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
  public function getTargetEntityType(): ?string {
    return $this->getComponent()->getTargetEntityTypeId();
  }

  /**
   * {@inheritDoc}
   */
  public function getTargetEntityBundle(): ?string {
    return $this->getComponent()->getTargetEntityBundle();
  }

  /**
   * Retrieves the format configuration for the current schema.
   *
   * This method checks if the schema has a defined format and if that format
   * exists in the plugin definition's formats. If both conditions are met,
   * it returns the corresponding format configuration. Otherwise, it returns
   * NULL.
   *
   * @param string|null $prop
   *   The property to retrieve from the format configuration. If NULL,
   *   the entire format configuration will be returned.
   *
   * @return array|string|null
   *   The format configuration as an associative array if available, or NULL
   *   if the format is not defined or does not exist in the plugin definition.
   */
  protected function getFieldFormat(?string $prop = NULL): array|string|null {
    $format = $this->getFormat();
    if (!empty($format) && isset($this->pluginDefinition['formats'][$format])) {
      $format = $this->pluginDefinition['formats'][$format];
      return $prop ? $format[$prop] ?? NULL : $format;
    }
    return NULL;
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
    if ($formatFieldType = $this->getFieldFormat('default_field_type')) {
      $fieldType = $formatFieldType;
    }
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
      array_walk($options, function (&$v, $k) {
        $v = [
          'value' => $k,
          'label' => $v,
        ];
      });
      $settings['allowed_values'] = array_values($options);
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
      return array_map(fn ($v) => [
        'value' => $v,
        'label' => ucwords(str_replace(['-', '_'], ' ', (string) $v)),
      ], $this->schema['enum']);
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
  public function getPropValue(): mixed {
    return $this->getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(): mixed {
    // If the value is set to be empty (which will cause it to be hidden), we
    // don't need to do anything else.
    if ($this->getOptionEmpty()->isEnabled()) {
      return [];
    }
    $value = $this->getFieldItemValue();
    $value = $this->denormalizeValue($value);
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
    foreach ($this->getValueCollection()->getAllowedInstances('modify') as $instance) {
      $value = $instance->modifyValue($value);
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
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
  public function denormalizeValue(array $field_item_value): mixed {
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
      $value = $this->getDefaultSchemaValue();
      $instances = $this->getValueCollection()->getAllowedInstances('default');
      foreach ($instances as $instance) {
        $value = $instance->provideDefaultValue($value);
        if (!$instance->shouldContinueProcessing()) {
          break;
        }
      }
      if ($fieldDefaultValue = $this->getFieldDefaultValue()) {
        $value = $fieldDefaultValue;
      }
      // Set the value so providers can use it.
      $this->setFieldItemValue($value);
      // Allow providers to modify the final default value.
      foreach ($instances as $instance) {
        $value = $instance->alterValue($value, 'default');
      }
      $this->defaultValue = $value;
    }
    return $this->defaultValue;
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultSchemaValue(): mixed {
    return $this->schema['examples'] ?? [];
  }

  /**
   * Retrieves the default value for a field.
   *
   * This method loads the default value from the field if the current shape
   * is the root entity shape and the scope is 'entity'. It will load the field
   * config component and then get the shape value.
   *
   * @return mixed
   *   The default value of the field.
   */
  protected function getFieldDefaultValue(): mixed {
    $value = [];
    // Load default value from field if this is the root entity shape.
    if ($this->isRoot() && $this->getScope() === 'entity') {
      $component = $this->getComponent();
      if ($component instanceof ComponentEntityInterface) {
        if ($fieldComponent = $component->getFieldComponent()) {
          $value = $fieldComponent->getPropShape($this->getName())->getValue();
        }
      }
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultFieldItemValue(): array {
    $fieldItem = clone $this->fieldItem;
    $fieldItem->setValue($this->getDefaultValue());
    return $fieldItem->getValue();
  }

  /**
   * {@inheritDoc}
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
  public function setParentValue(mixed $value): self {
    $this->parentValue = $value;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getParentValue(): mixed {
    return $this->parentValue;
  }

  /**
   * {@inheritDoc}
   */
  public function isEmpty(): bool {
    return $this->isFieldItemEmpty();
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
    if (is_array($value) && !$this->isIterable()) {
      $value = $value[0] ?? $value;
    }
    $this->fieldItem->setValue($value);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function modifyAttributes(Attribute $attributes) {
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
    if (!isset($this->widget)) {
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
      $this->widget = $this->widgetManager->getInstance($options + [
        'prepare' => TRUE,
      ]) ?: NULL;
    }
    return $this->widget;
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
    if ($formatWidgetType = $this->getFieldFormat('default_field_widget')) {
      $widgetType = $formatWidgetType;
    }
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
   * {@inheritDoc}
   */
  public function setWidgetSetting(string $key, mixed $value): self {
    $this->widgetSettings[$key] = $value;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setWidgetSettings(array $settings): self {
    $this->widgetSettings = $settings;
    return $this;
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

    $optionEmpty = $this->getOptionEmpty();
    $optionDefault = $this->getOptionDefault();
    $optionAccess = $this->getOptionAccess();

    if ($this->getScope() !== 'config' && $optionAccess->isDisabled()) {
      return $form;
    }

    $form['#attributes']['class'][] = 'neo-alchemist--component-form';
    $form['#attached']['library'][] = 'neo_alchemist/component.form';

    $this->prepForm($form, $form_state);

    $parents = array_merge($form['#parents'], [$this->getName()]);
    $id = Html::getId('shape-form-' . implode('-', $parents));
    $form += [
      '#type' => 'container',
    ];
    $form['#tree'] = TRUE;
    $form['#parents'] = $parents;
    $form['#id'] = $id;

    if (
      ($optionEmpty->isDisabled() || $optionDefault->isFormForced())
      &&
      ($optionDefault->isDisabled() || $optionEmpty->isFormForced())
    ) {
      $form = $this->form($form, $form_state);
    }

    $form['_options'] = [
      '#type' => 'container',
      '#weight' => !empty($form['#title']) ? -10 : 0,
      '#neo_fieldset_region' => 'legend_end',
      '#access' => FALSE,
      '#attributes' => [
        'class' => [
          'form--inline',
          'form--inline-min',
          'whitespace-nowrap',
          'items-center',
        ],
      ],
    ];
    if ($optionAccess->isAllowed()) {
      $form['_options']['access'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow Edit'),
        '#description' => $this->t('Allow  @label to be changed', ['@label' => $this->getTitle()]),
        '#tooltip' => TRUE,
        '#default_value' => $optionAccess->isEnabled(),
        '#neo_size' => 'xs',
      ];
    }
    $states = [];
    if ((!$optionEmpty->isFormForced() || $optionDefault->isFormForced()) && $optionDefault->isAllowed()) {
      if ($optionDefault->isEnabled()) {
        $states[] = $this->t('Default');
      }
      $form['_options']['default'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Default'),
        '#description' => $this->t('Use the default value of @label', ['@label' => $this->getTitle()]),
        '#default_value' => $optionDefault->isEnabled(),
        '#access' => $optionDefault->isFormForced() || $optionEmpty->isDisabled(),
        '#neo_size' => 'xs',
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }
    if ((!$optionDefault->isFormForced() || $optionEmpty->isFormForced()) && $optionEmpty->isAllowed()) {
      if ($optionEmpty->isEnabled()) {
        $states[] = $this->t('Hidden');
      }
      $form['_options']['empty'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hide'),
        '#description' => $this->t('Do not show @label', ['@label' => $this->getTitle()]),
        '#tooltip' => TRUE,
        '#default_value' => $optionEmpty->isEnabled(),
        '#access' => $optionEmpty->isFormForced() || $optionDefault->isDisabled(),
        '#neo_size' => 'xs',
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $id,
        ],
      ];
    }

    if (!empty(Element::children($form['_options']))) {
      if ($optionDefault->isFormForced() || $optionEmpty->isFormForced()) {
        $form['#title'] = $this->getTitle();
      }
      if ($states) {
        $form['#title'] = $this->t('@label (@states)', [
          '@label' => $this->getTitle(),
          '@states' => implode(' & ', $states),
        ]);
      }
      $form['#type'] = 'fieldset';
      $form['_options']['#access'] = TRUE;
      $form['#required'] = $this->isRequired();
    }

    foreach ($this->getValueCollection()->getAllowedInstances('form') as $instance) {
      $instance->formAlter($form, $form_state);
    }

    return $form;
  }

  /**
   * Prepare form.
   */
  protected function prepForm(array &$form, FormStateInterface $form_state): void {
    $id = $this->id();
    // Restore previous set values when toggling ajax options.
    if ($form_state->get(['previous_value', $id]) === NULL) {
      $form_state->set(['previous_value', $id], $this->getFieldItemValue());
    }
    foreach ($this->options as $type => $option) {
      $previousKey = 'previous_' . $type;
      $status = $option->isEnabled();
      if ($form_state->get([$previousKey, $id]) === NULL) {
        $form_state->set([$previousKey, $id], $status);
      }
      $previousStatus = $form_state->get([
        $previousKey,
        $id,
      ]);
      if ($previousStatus && !$status) {
        $this->setFieldItemValue($form_state->get(['previous_value', $id]));
      }
      if (!$previousStatus && $status) {
        $valueParents = array_merge($form['#parents'], [
          $this->getName(),
          $this->getName(),
        ]);
        array_shift($valueParents);
        $values = $form_state->getValue($valueParents) ?? [];
        $widget = $this->getWidget();
        if ($widget) {
          $values = $widget->massageFormValues($values, $form, $form_state)[0] ?? [];
          $form_state->set(['previous_value', $id], $values);
        }
      }
      $form_state->set([
        $previousKey,
        $id,
      ], $status);
    }
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
        '#parents' => $form['#parents'],
      ];
      $form['widget'] = $widget->form($this->getFieldItemList(), $form['widget'], $form_state);
      $this->formWidgetAlter($form['widget'], $form_state);
    }
    return $form;
  }

  /**
   * Alter the widget form.
   *
   * This method can be overridden by extending classes to add additional form
   * elements to the widget form.
   *
   * @param array $form
   *   The widget form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    // This method can be overridden by extending classes to add additional form
    // elements to the widget form.
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
    $options = $form_state->getValue('_options') ?? [];
    if (isset($options['default'])) {
      $options['default'] = (int) $options['default'];
    }
    if (isset($values['empty'])) {
      $options['empty'] = (int) $options['empty'];
    }
    if (isset($values['access'])) {
      $options['access'] = (int) $options['access'];
    }
    $this->setOptions($options);
    // Remove options so that they are not processed or stored.
    $form_state->unsetValue('_options');
    if (empty($values) && $this->isRequired()) {
      $form_state->setError($form, $this->getTitle() . ' is required.');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function massageFormValues(array $values, array $original_values, array $form, FormStateInterface $form_state): ?array {
    if ($this->getOptionDefault()->isEnabled() && !$this->getOptionDefault()->isFormForced()) {
      // We marked as default, we continue to store the value so that it can
      // be restored by the user.
      return $original_values;
    }
    // Remove options so that they are not processed or stored.
    unset($values['_options']);
    $storedValues = $values;
    if (isset($values[$this->getName()]) && ($widget = $this->getWidget())) {
      $massagedValues = $widget->massageFormValues($values[$this->getName()], $form, $form_state);
      // @todo 'values' is checked for checkboxes.
      $massagedValues = $massagedValues[0] ?? $massagedValues['value'] ?? [];
      $fieldItem = clone $this->fieldItem;
      $fieldItem->setValue($massagedValues);
      $fieldItem->preSave();
      $actualValues = $fieldItem->getValue();
      $storedValues = array_intersect_key($actualValues, $fieldItem->getProperties(FALSE));
    }

    foreach ($this->getValueCollection()->getAllowedInstances('form') as $instance) {
      $instance->massageValuesAlter($storedValues, $original_values, $form, $form_state);
    }
    return $storedValues;
  }

  /**
   * {@inheritDoc}
   */
  public function getOptionEmpty(): ComponentShapeOption {
    return $this->options['empty'];
  }

  /**
   * {@inheritDoc}
   */
  public function getOptionDefault(): ComponentShapeOption {
    return $this->options['default'];
  }

  /**
   * {@inheritDoc}
   */
  public function getOptionAccess(): ComponentShapeOption {
    return $this->options['access'];
  }

  /**
   * {@inheritDoc}
   */
  public function setDefaultOptions(array $options, ?string $id = NULL): self {
    $id = $id ?? $this->id();
    match ($this->isRoot()) {
      TRUE => $this->defaultNestedOptions[$id] = $options,
      FALSE => $this->getRootShape()->setDefaultOptions($options, $id),
    };
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setDefaultNestedOptions(array $options): self {
    match ($this->isRoot()) {
      TRUE => $this->defaultNestedOptions = NestedArray::mergeDeep($options, $this->defaultNestedOptions),
      FALSE => $this->getRootShape()->setDefaultNestedOptions($options),
    };
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setOptions(array $options, ?string $id = NULL): self {
    $id = $id ?? $this->id();
    match ($this->isRoot()) {
      TRUE => $this->nestedOptions[$id] = $options,
      FALSE => $this->getRootShape()->setOptions($options, $id),
    };
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getOptions(?string $id = NULL): array {
    $id = $id ?? $this->id();
    return $this->getNestedOptions()[$id] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function setNestedOptions(array $options): self {
    match ($this->isRoot()) {
      TRUE => $this->nestedOptions = NestedArray::mergeDeep($options, $this->nestedOptions),
      FALSE => $this->getRootShape()->setNestedOptions($options),
    };
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getNestedOptions(): array {
    return match ($this->isRoot()) {
      TRUE => $this->nestedOptions + $this->defaultNestedOptions,
      FALSE => $this->getRootShape()->getNestedOptions(),
    };
  }

  /**
   * {@inheritDoc}
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
  public function supportsFieldProperties(FieldDefinitionInterface $entityFieldDefinition, array $entityFieldProperties): bool {
    if (count($entityFieldProperties) === 1) {
      $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
      if (count($shapeFieldProperties) === 1) {
        // When both the shape and the field have only one property, we can
        // match them directly.
        return $this->supportsShapeFieldProperty(reset($shapeFieldProperties), reset($entityFieldProperties));
      }
    }
    else {
      if (isset($entityFieldProperties['type']) && isset($entityFieldProperties['value'])) {
        $properties = [];
        if ($this instanceof ComponentShapeChildrenPluginInterface) {
          foreach ($this->getChildShapes() as $childShape) {
            $properties[$childShape->getName()] = $childShape->getFieldItem()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
          }
        }
        $needs = array_values(array_unique(array_map(fn ($v) => $v->getDataType(), $entityFieldProperties)));
        $has = array_values(array_unique(array_map(fn ($v) => reset($v)->getDataType(), $properties)));
        return !empty(array_intersect($needs, $has));
      }
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function supportsFieldProperty(FieldDefinitionInterface $entityFieldDefinition, DataDefinitionInterface $entityFieldProperty): bool {
    $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
    if (count($shapeFieldProperties) > 1) {
      // This shape has more than one property and cannot by matched by a single
      // property.
      return FALSE;
    }
    foreach ($shapeFieldProperties as $shapeFieldProperty) {
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
  public function getMatches(FieldDefinitionInterface $entityFieldDefinition) {
    return [];
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
   * Called when a component is generated using this shape.
   *
   * @param array $prop
   *   The prop array that is being generated.
   * @param array $vars
   *   The variables that are being used for generation.
   * @param \Drupal\neo_alchemist\Interviewer\Interviewer $ir
   *   The interviewer that is being used for generation.
   * @param \Drupal\neo_alchemist\Generator\NeoComponentPropGeneratorInterface $generator
   *   The generator that is being used for generation.
   * @param array $parents
   *   The parent labels.
   */
  public static function onGeneration(array &$prop, array $vars, Interviewer $ir, NeoComponentPropGeneratorInterface $generator, array $parents) {
  }

  /**
   * Get default examples for this shape.
   *
   * @return mixed
   *   The default examples for this shape.
   */
  public static function getGenerationExamples(array $prop) {
    return [];
  }

  /**
   * Get the Twig template for this shape.
   *
   * This method can be overridden by shapes to provide custom Twig
   * generation.
   *
   * @param \Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig $twig
   *   The NeoComponentTwig instance.
   */
  public static function onGenerateTwig(NeoComponentTwig $twig) {
    $twig->setPrefix('{% if ' . $twig->getName() . ' %}');
    $twig->setSuffix('{% endif %}');
    return $twig;
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
    return $this->getType() === self::ARRAY;
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
    unset($this->widget);
  }

}
