<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentPropGeneratorInterface;
use Drupal\neo_alchemist\Drush\Generators\NeoComponentTwig;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use DrupalCodeGenerator\InputOutput\Interviewer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for neo_component_shape plugins.
 *
 * Declares ComponentShapeSetupInterface rather than the union it extends: a
 * shape class is what the plugin manager builds, so it starts out as a shape
 * under construction and satisfies both. Which of the two a *caller* holds is
 * what decides whether the setup setters are available to it, and the manager
 * is the only thing that hands out the wider type.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeSetupInterface
 */
abstract class ComponentShapePluginBase extends PluginBase implements ComponentShapeSetupInterface, ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The field item.
   *
   * @var \Drupal\Core\Field\FieldItemInterface
   */
  protected FieldItemInterface $fieldItem;

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
   * Whether the default value has been resolved.
   *
   * A separate flag rather than isset($this->defaultValue): NULL is a
   * legitimate computed default (a provider chain can end in NULL), and it
   * must memoise like any other value.
   *
   * @var bool
   */
  protected bool $defaultValueResolved = FALSE;

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
   * A list of plugins allowed to be initialized.
   *
   * @var bool[]
   */
  protected array $allowInitPlugins = [];

  /**
   * The shape plugin settings.
   *
   * @var array
   */
  protected array $plugins = [];

  /**
   * The nested options for this shape's whole tree.
   *
   * Only ever populated on the root shape — every other shape reaches it
   * through a view, which is what makes a parent's decision about a child the
   * same record the child later reads for itself.
   *
   * @var \Drupal\neo_alchemist\NestedOptionMap|null
   */
  protected ?NestedOptionMap $nestedOptionMap = NULL;

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
  protected CacheableMetadata $cacheableMetadata;

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
    $this->schema = $this->buildSchema($schema);
    // Set default options.
    $this->options['empty'] = new ComponentShapeOption($this->optionEmptyInitValue, $this->optionEmptyInitAccess);
    $this->options['default'] = new ComponentShapeOption($this->optionDefaultInitValue, $this->optionDefaultInitAccess);
    $this->options['access'] = new ComponentShapeOption(TRUE, FALSE);

    // Only set settings if the shape has not changed.
    // We migrated from using 'shape' to 'ref' to identify shapes values. As
    // a result, if ref is not set, we allow the override to provide backwards
    // compatibility.
    if (empty($settings['ref']) || ($settings['ref'] === $this->getRef())) {
      // Initialize settings.
      $this->setActive($settings['active'] ?? TRUE);
      $this->setExpanded($settings['expanded'] ?? []);
      // A prop with no stored setting is one the component's template has just
      // grown, so which way it defaults is the component's policy rather than
      // a hardcoded answer. The same applies above when the ref no longer
      // matches: a prop whose shape changed re-derives from that policy
      // instead of silently reopening.
      $this->setEditable($settings['editable'] ?? $component->getPropEditableDefault());
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
  public function id(bool $ignoreDelta = FALSE): string {
    $id = implode('~', $this->getNestedPath());
    if (!$ignoreDelta) {
      $delta = $this->getDelta();
      if ($delta !== NULL) {
        $id .= "~$delta";
      }
    }
    return $id;
  }

  /**
   * Get the nested delta of the shape.
   *
   * @return int|null
   *   The nested delta.
   */
  protected function getDelta(): ?int {
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
    if (!isset($this->cacheableMetadata)) {
      $this->cacheableMetadata = new CacheableMetadata();
    }
    return $this->cacheableMetadata;
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
   *
   * Declared as the union rather than `self` so the handoff survives a
   * concretely-typed handle: `$shape->init()` gives back an initialised shape
   * even where $shape is a shape class rather than the setup interface. `self`
   * here would resolve to the class, which still has the setup setters on it.
   * The six subclasses that override this declare the union for the same
   * reason.
   */
  public function init(): ComponentShapePluginInterface {
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

    // Initialize the options.
    $this->initOptions();

    // Create the field item.
    $this->fieldItem = $this->buildFieldItem($this->getFieldType(), $this->getFieldStorageSettings(), $this->getFieldInstanceSettings());
    $defaultValue = $this->getDefaultValue();
    $this->setFieldItemValue($defaultValue, FALSE);

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

    // "No override value" below follows isProvidedValueEmpty(), the value
    // pipeline's emptiness contract, rather than PHP truthiness: an override
    // of 0, '0' or FALSE is a value and must not be mistaken for an absent
    // one. Overrides normally arrive in wrapped field-item form
    // (['value' => '0']), which is a non-empty array and so was never at risk
    // — this states the intent rather than relying on the storage format.
    $instances = $this->getValueCollection()->getAllowedInstances('value');
    foreach ($instances as $instance) {
      $overrideValue = $instance->provideOverrideValue($overrideValue, $defaultValue);
      if (!$this->isProvidedValueEmpty($overrideValue)) {
        $this->setFieldItemValue($overrideValue, FALSE);
      }
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }

    // If we have no override value and the shape is required, we set it to
    // NULL so that the default value is used.
    if ($this->isProvidedValueEmpty($overrideValue) && $this->isRequired()) {
      $overrideValue = NULL;
    }

    // If we have no override value and the shape is set to use default, we
    // set it to NULL so that the default value is used.
    if ($this->isProvidedValueEmpty($overrideValue) && $this->getOptionDefault()->isEnabled()) {
      $overrideValue = NULL;
    }

    if (!is_null($overrideValue)) {
      $this->setFieldItemValue($overrideValue);
    }
    // This shape's children read their options and the decisions a producer
    // made about them as they are built, from here onwards, so either written
    // later changes nothing. Both stores take the deadline that setters on the
    // shape used to assert; neither writer is a shape method any more, so the
    // setup interface cannot withdraw them and a seal carries it instead.
    $this->getNestedOptionMap()->seal();
    $this->sealChildShapeState();
    $this->initialized = TRUE;

    return $this;
  }

  /**
   * Closes this shape's producer decisions about its children.
   *
   * A no-op here because a shape with no children has none. Overridden by
   * ChildShapeStateTrait, which the two children-bearing bases use.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildShapeStateTrait::sealChildShapeState()
   * @see \Drupal\neo_alchemist\ChildShapeState::seal()
   */
  protected function sealChildShapeState(): void {
  }

  /**
   * Initializes the plugins for the component shape.
   */
  protected function initPlugins() {
    foreach ($this->getDefaultPlugins() as $pluginId => $settings) {
      if ($this->allowInitPlugins[$pluginId] ?? TRUE) {
        $this->addPlugin($pluginId, $settings);
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function allowInitPlugins(string $pluginId, bool $allow = TRUE): self {
    // No `assert(!$this->isInitialized())` here, nor on ::setOverrideValue().
    // Both used to carry one; ComponentShapeSetupInterface carries it now, and
    // an assertion that duplicates the type is the thing this ticket set out
    // to remove rather than a second line of defence.
    $this->allowInitPlugins[$pluginId] = $allow;
    return $this;
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
    $options = $this->getOptions($this->id());
    if (!$options && $this->getDelta() !== NULL) {
      // When a delta is set, we also check for options stored without the
      // delta.
      $options = $this->getOptions($this->id(TRUE)) + $options;
    }
    if ($options) {
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
    $fieldItemDefinition = static::createFieldStorageDefinition($fieldType)->getItemDefinition();
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
   * Builds a pristine field storage definition for a field type.
   *
   * Every shape needs its own storage definition — buildFieldItem() stamps the
   * prop's name, label, description and required flag onto it, and
   * getFieldItemList() later stamps the host entity type — so they cannot be
   * shared. Building one from scratch is what costs: FieldStorageDefinition
   * ::create() asks the field type plugin manager for the type's default
   * storage *and* field settings on every call, and a single component render
   * builds thousands of shapes (~70 per component tree here).
   *
   * The default settings for a field type are fixed for the life of the
   * request, so the expensive part is done once per type and each caller gets
   * a clone. BaseFieldDefinition::__clone() deep-clones the item definition and
   * repairs its back-reference to the cloned parent, so the prototype is never
   * reachable from — or mutated by — anything handed out here.
   *
   * @param string $fieldType
   *   The field type ID.
   *
   * @return \Drupal\neo_alchemist\PropSource\FieldStorageDefinition
   *   An unshared storage definition carrying the type's default settings.
   */
  protected static function createFieldStorageDefinition(string $fieldType): FieldStorageDefinition {
    static $prototypes = [];
    if (!isset($prototypes[$fieldType])) {
      $prototypes[$fieldType] = FieldStorageDefinition::create($fieldType);
    }
    return clone $prototypes[$fieldType];
  }

  /**
   * Retrieves the supported field types for the plugin.
   *
   * This method returns an array of supported field types defined
   * in the plugin definition. If no supported field types are
   * defined, an empty array is returned.
   *
   * @return array
   *   An array of supported field property types.
   */
  protected function getSupportedFieldTypes(): array {
    $props = $this->pluginDefinition['supports_field_types'] ?? [];
    // Read the same definition the support predicates use, not the raw field
    // item: a shape may redirect support checks elsewhere (ArrayShape defers
    // to its single child), and the accepted-type list has to follow, or the
    // predicates and the list they consult describe different shapes.
    $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
    if (count($shapeFieldProperties) === 1) {
      // If shape has only one property, we can use the field property type.
      $props[] = reset($shapeFieldProperties)->getDataType();
    }
    return array_unique($props);
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
    // Same definition as the support predicates — see getSupportedFieldTypes().
    // Reading the raw field item here left a single-prop array asking about
    // its own `map` storage, which exposes no properties at all, so the list
    // came back empty and no property could ever match.
    $shapeFieldProperties = $this->getFieldDefinitionForSupportCheck()->getFieldStorageDefinition()->getPropertyDefinitions();
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
  public function onUpdate(): void {
    if ($this->allowConfigurablePlugins()) {
      $this->initPlugins();
      // Every active plugin is notified, regardless of group. (This used to
      // pass 'update' as the group id, which was silently ignored back when
      // getActiveInstances() did not implement its group filter.)
      foreach ($this->getValueCollection()->getActiveInstances() as $instance) {
        $instance->onUpdate();
      }
    }
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
   * Build the schema on initialization.
   */
  protected function buildSchema($schema): array {
    return $schema;
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
   * Retrieves the structure of the component shape.
   *
   * Every shape in this subtree, this one included, as nested ID => prop ref.
   * Sorted by ID so the same tree always yields the same array, which is what
   * lets getExpression() flatten it into a comparable string.
   *
   * @return array
   *   Prop refs keyed by nested ID, sorted by key.
   */
  protected function getStructure(): array {
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
   * Retrieves the path of component names from the root down to this shape.
   *
   * @param bool $includeRoot
   *   (optional) Whether to keep the root shape's name — the outermost parent,
   *   or this shape's own name when it has no parents. Defaults to TRUE. The
   *   interface docblock this moved from said "the current component", which
   *   the body has never done: it drops the *first* segment, not the last.
   *
   * @return array
   *   The component names, outermost first, ending with this shape's own.
   */
  protected function getNestedPath($includeRoot = TRUE): array {
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
    $titles = array_map(fn($parent) => (string) $parent->getTitle(), $this->parents);
    $delta = $this->getDelta();
    if ($delta !== NULL && $titles) {
      // The delta is stored on this shape but identifies a row of the iterable
      // that owns it, so the index belongs on the parent's segment. Without it
      // every region in an array prop renders the same label ("Items: Region")
      // in the layout editor's breadcrumb and overlay, leaving sibling regions
      // indistinguishable.
      $index = array_key_last($titles);
      $titles[$index] .= ' ' . ($delta + 1);
      if ($label = $this->getDeltaLabel($delta)) {
        $titles[$index] .= ' "' . $label . '"';
      }
    }
    $titles[] = (string) $this->getTitle();
    if (!$includeRoot) {
      array_shift($titles);
    }
    return implode(': ', $titles);
  }

  /**
   * A human-readable name for one row of this shape's parent iterable.
   *
   * Falls back to NULL so callers keep the bare index. Picks the first
   * non-empty string sibling — typically a title/heading prop — which makes a
   * repeated region identifiable by its row's content rather than by number
   * alone.
   *
   * @param int $delta
   *   The row to label.
   *
   * @return string|null
   *   The label, or NULL when the row has no usable string value.
   */
  protected function getDeltaLabel(int $delta): ?string {
    if (!$this->parents) {
      return NULL;
    }
    $parent = $this->parents[array_key_last($this->parents)];
    $row = $parent->getFieldItemValue()[$delta] ?? NULL;
    if (!is_array($row)) {
      return NULL;
    }
    $ownName = $this->getName();
    foreach ($row as $name => $value) {
      if ($name === $ownName || !is_string($value)) {
        continue;
      }
      $value = trim(strip_tags($value));
      if ($value === '') {
        continue;
      }
      return Unicode::truncate($value, 40, TRUE, TRUE);
    }
    return NULL;
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
   * {@inheritDoc}
   */
  public function hasPlugin(string $pluginId): bool {
    foreach ($this->getPlugins() as $plugins) {
      if (isset($plugins[$pluginId])) {
        return TRUE;
      }
    }
    return FALSE;
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
    if ($this->getValueCollection()->hasActiveInstance($pluginId)) {
      return $this;
    }
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
  public function getDefaultPlugins(): array {
    $definition = $this->getPluginDefinition();
    $plugins = [];
    if (!empty($definition['default_plugins'])) {
      foreach ($definition['default_plugins'] as $pluginId => $settings) {
        if (!is_array($settings)) {
          $pluginId = $settings;
          $settings = [];
        }
        $plugins[$pluginId] = $settings;
      }
    }
    return $plugins;
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
      // Iterable roots keep their own list-level value providers/modifiers
      // (the "Base" group in the prop form) even while expanded: a provider
      // such as "menu" can generate the whole list while the expanded child
      // shapes configure each individual item. Non-iterable roots delegate
      // entirely to their expanded children and keep no plugins of their own.
      if ($this->isIterable()) {
        return TRUE;
      }
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
      $stored = $plugins[$this->id()] ?? [];
      $definitions = $this->valueManager->getFilteredDefinitionsFromShape($this);
      // Order the plugins by group first, then within each group. The group
      // states the role a plugin plays, and the processing loops walk this one
      // list across every group, so the group's weight is what makes a
      // `providers` plugin run before the terminal `fallback` — never the saved
      // order. Ordering flat by saved order instead lets `default` (fallback)
      // run first whenever the site builder enabled it before the provider; the
      // provider then overwrites the default it had just supplied with its own
      // empty result and the configured default silently disappears.
      //
      // Inside a group the saved (drag-and-drop) order wins — that is the only
      // order the prop form can express, since each group renders its own table
      // — followed by the remaining available plugins in their natural
      // definition order (weight, then label).
      $byGroup = [];
      foreach ($definitions as $pluginId => $definition) {
        $byGroup[$definition['group']][$pluginId] = $pluginId;
      }
      $orderedIds = [];
      foreach ($this->valueManager->getGroupOrder() as $groupId) {
        if (empty($byGroup[$groupId])) {
          continue;
        }
        $groupIds = $byGroup[$groupId];
        unset($byGroup[$groupId]);
        $savedIds = array_keys(array_intersect_key($stored, $groupIds));
        $orderedIds = array_merge($orderedIds, $savedIds, array_values(array_diff($groupIds, $savedIds)));
      }
      // Any group the group manager does not know about keeps its definition
      // order and trails the known groups.
      foreach ($byGroup as $groupIds) {
        $orderedIds = array_merge($orderedIds, array_values($groupIds));
      }
      foreach ($orderedIds as $pluginId) {
        $configurations[$pluginId] = [
          'id' => $pluginId,
          'status' => !empty($stored[$pluginId]),
          'settings' => $stored[$pluginId]['settings'] ?? [],
        ];
      }
      $this->valueCollection = new ComponentShapePluginCollection($this, $this->valueManager, $configurations);
    }
    return $this->valueCollection;
  }

  /**
   * {@inheritDoc}
   */
  public function allowValuePlugin(array $definition): bool {
    return TRUE;
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
   * Checks if the component shape is expanded.
   *
   * This method determines if the current instance implements the
   * ComponentShapeExpandedPluginInterface, allows expansion, and if the
   * nested ID of the component is in the list of expanded components.
   *
   * @return bool
   *   TRUE if the component shape is expanded, FALSE otherwise.
   */
  protected function isExpanded(): bool {
    return $this->isExpandable() && in_array($this->id(), $this->getExpanded());
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
    $this->getOptionEmpty()->setAccess(FALSE, 'Shape is required and cannot be empty.');
    $this->getOptionEmpty()->setLockedValue(FALSE, 'Shape is required and cannot be empty.');
    return $this;
  }

  /**
   * Checks if the required enforcement is enabled.
   *
   * @return bool
   *   TRUE if the required enforcement is enabled, FALSE otherwise.
   */
  protected function isEnforcedRequired(): bool {
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
    return $this->required && $this->allowRequired();
  }

  /**
   * {@inheritDoc}
   */
  public function allowRequired(): bool {
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function allowUnsetEmpty(): bool {
    return !$this->isRequired();
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
  public function getEditable(): bool {
    return $this->editable;
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
   * Sets the locked state of the component.
   *
   * @param bool $locked
   *   (optional) The locked state to set. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  protected function enforceLocked(bool $locked = TRUE): self {
    $this->enforceLocked = $locked;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function isLocked(): bool {
    if ($this->enforceLocked) {
      // If we are already locked, no reason to continue.
      return TRUE;
    }
    // The component-level lock overrides every stored per-prop setting,
    // including props the template grew after this component was last
    // configured. Kept outside the $this->locked memo on purpose: the memo can
    // outlive a mode change within a single request, and this is one property
    // read. Kept above the memo block so it never enters the expensive and
    // re-entrant value collection path below.
    if ($this->getComponent()->arePropsLocked()) {
      return TRUE;
    }
    if (!isset($this->locked)) {
      $this->locked = FALSE;
      if ($this->getOptionAccess()->isDisabled()) {
        $this->locked = TRUE;
        return $this->locked;
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
   * Get the field storage settings.
   *
   * @return array
   *   The field storage settings.
   */
  protected function getFieldStorageSettings(): array {
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
   * Get the field instance settings.
   *
   * @return array
   *   The field instance settings.
   */
  protected function getFieldInstanceSettings(): array {
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
  public function getFieldOptions(): array {
    if (array_key_exists('enum', $this->schema)) {
      $options = [];
      foreach ($this->schema['enum'] as $v) {
        $options[$v] = ucwords(str_replace(['-', '_'], ' ', (string) $v));
      }
      return $options;
    }
    return [];
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
        $field->setContext($fieldStorageDefinition->getName(), EntityAdapter::createFromEntity($hostEntity));
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
   *
   * A forward to ::getValue(), and deliberately only that. It earns its keep on
   * the signature rather than the body: this is the render role's entry point,
   * where the attributes are required, while ::getValue() takes them optionally
   * because most of its callers are forms that are not rendering at all.
   */
  public function getPropValue(Attribute $attributes): mixed {
    return $this->getValue($attributes);
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(?Attribute $renderAttributes = NULL): mixed {
    // If the value is set to be empty (which will cause it to be hidden), we
    // don't need to do anything else.
    if ($this->getOptionEmpty()->isEnabled()) {
      return [];
    }
    $value = $this->buildValue($renderAttributes);
    if ($renderAttributes !== NULL) {
      $value = $this->buildRenderValue($value, $renderAttributes);
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function isProvidedValueEmpty(mixed $value): bool {
    if (!is_array($value)) {
      // A scalar carries nothing only when it is NULL or the empty string.
      // This deliberately avoids empty(): `0`, `'0'` and FALSE are values a
      // number or boolean prop can legitimately be provided, and treating them
      // as empty would make a provider fall through to the fallback.
      return $value === NULL || $value === '';
    }
    // Presentational keys never make a value non-empty. Which keys those are
    // is the shape's own business — see ::getPresentationalValueKeys().
    foreach ($this->getPresentationalValueKeys() as $key) {
      unset($value[$key]);
    }
    return empty($value);
  }

  /**
   * The value keys that carry presentation rather than content.
   *
   * A composite value made up *entirely* of these keys carries nothing an
   * editor put there, so ::isProvidedValueEmpty() reports it empty. Override
   * this in a shape whose schema always resolves some child regardless of
   * authored input — otherwise that child alone keeps the whole value looking
   * non-empty, which starves the fallback plugin and, for shapes that collapse
   * an empty render value, leaves templates unable to test the prop at all.
   *
   * Empty by default, because "this key is presentation" is a fact about one
   * shape's schema, not about values in general. The base used to name `size`
   * for everybody, which meant a component author's object prop whose only
   * child happened to be called `size` — a spacer, a gap — resolved as empty
   * and was dropped from its parent with nothing to show for it. Each shape
   * that has such a key now says so itself.
   *
   * @return string[]
   *   Value keys that do not count as content.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ImageShape::getPresentationalValueKeys()
   * @see \Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape::getPresentationalValueKeys()
   */
  protected function getPresentationalValueKeys(): array {
    return [];
  }

  /**
   * Builds the value for the component shape.
   *
   * @param \Drupal\Core\Template\Attribute|null $renderAttributes
   *   The wrapper attributes when this value is being built for rendering, NULL
   *   when it is not. Shapes that build children pass this straight down, so a
   *   nested shape renders on the same terms as the one that asked for it.
   *
   * @return mixed
   *   The built value.
   */
  protected function buildValue(?Attribute $renderAttributes = NULL): mixed {
    if ($this->getOptionDefault()->isEnabled()) {
      $value = $this->getDefaultFieldItemValue();
    }
    else {
      $value = $this->getFieldItemValue();
    }
    $value = $this->denormalizeValue($value);
    if (is_null($value)) {
      return [];
    }
    $value = $this->adaptValue($value);
    if (!empty($this->schema['properties'])) {
      if (!is_array($value)) {
        // If we do not have an array we assume we have an incorrect value.
        $value = $this->buildDefaultValue($renderAttributes);
      }
    }
    return $value;
  }

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
  protected function adaptValue(mixed $value): mixed {
    return $value;
  }

  /**
   * Prepares the value before rendering.
   *
   * This method can be overridden in subclasses to modify the value
   * before it is rendered. By default, it returns the value unchanged.
   *
   * The returned value must pass validation against the schema.
   *
   * @param mixed $value
   *   The value to be prepared.
   * @param \Drupal\Core\Template\Attribute $attributes
   *   The attributes that belong to the rendering component.
   */
  private function buildRenderValue(mixed $value, Attribute $attributes): mixed {
    $value = $this->resolveValue($value);
    $value = $this->preRenderValue($value, $attributes);
    foreach ($this->getValueCollection()->getAllowedInstances('modify') as $instance) {
      $value = $instance->modifyValue($value);
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    if ($value instanceof Attribute && $value !== $attributes && $this->getComponent()->isEditorPreview()) {
      // Stamped after preRenderValue() so a style shape's merge into the
      // shared component attributes cannot carry a child's id onto the root.
      $value->setAttribute('data-neo-prop', $this->id());
    }
    return $value;
  }

  /**
   * Prepares the value before rendering.
   *
   * This method can be overridden in subclasses to modify the value
   * before it is rendered. By default, it returns the value unchanged.
   *
   * The returned value must pass validation against the schema.
   *
   * @param mixed $value
   *   The value to be prepared.
   * @param \Drupal\Core\Template\Attribute $attributes
   *   The attributes that belong to the rendering component.
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
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
  final protected function denormalizeValue(array $field_item_value): mixed {
    // A whole-field entity match yields a delta-keyed list, e.g.
    // [0 => ['value' => 'x']]. A non-iterable shape expects a single item's
    // property-keyed value, so reduce to the first delta before extracting the
    // main property (mirrors the reduction setFieldItemValue() already does).
    // Safe because a genuine property-keyed value has no integer 0 key; only
    // iterable shapes keep the full list.
    if (!$this->isIterable() && array_key_exists(0, $field_item_value) && is_array($field_item_value[0])) {
      $field_item_value = $field_item_value[0];
    }
    return match (count($this->fieldItem->getDataDefinition()->getPropertyDefinitions())) {
      1 => $field_item_value[$this->fieldItem::mainPropertyName()] ?? NULL,
      default => $field_item_value,
    };
  }

  /**
   * {@inheritDoc}
   *
   * **This getter runs the provider chain, and it writes.** Both halves of
   * that are load-bearing and neither is visible from the name, so they are
   * stated here rather than left to whoever edits ::computeDefaultValue()
   * next.
   *
   * The memo is claimed BEFORE the pipeline runs, not after it returns. The
   * pipeline can re-enter this method — buildDefaultValue() runs during
   * child-schema loading — and claiming late made that re-entry re-run the
   * whole chain, whose mid-chain write to the field item then overwrote
   * authored values with a recomputed NULL. Claiming first also turns any
   * accidental first-pass re-entry into "return the not-yet-computed NULL"
   * rather than infinite recursion.
   *
   * The flag exists separately from $defaultValue rather than as an
   * isset() check on it, because NULL is a legitimate computed default and
   * must memoise like any other value.
   *
   * @see ::computeDefaultValue()
   */
  public function getDefaultValue(): mixed {
    if (!$this->defaultValueResolved) {
      // Claim, then compute. The two statements are in this order on purpose
      // and the split below is what keeps them from being reordered by
      // accident: nothing between them can re-enter, because there is nothing
      // between them.
      $this->defaultValueResolved = TRUE;
      $this->defaultValue = $this->computeDefaultValue();
    }
    return $this->defaultValue;
  }

  /**
   * Runs the value pipeline that produces this shape's default value.
   *
   * Schema example, then the provider chain, then any field default, then the
   * modifier pass — with a required-but-empty result reverting to the schema
   * example so SDC is never handed a missing required prop.
   *
   * **Call this from ::getDefaultValue() and nowhere else, and only once.** It
   * publishes intermediate values onto the field item mid-chain so that
   * providers can read back what the ones before them produced, and it can
   * re-enter ::getDefaultValue() while doing so. Running it a second time
   * therefore does not just cost time — it republishes over whatever the
   * shape now holds. The memo claim in ::getDefaultValue() is what stops that,
   * and this method is separate so the claim cannot drift below the work.
   *
   * Private rather than protected for the same reason: protected would let a
   * shape plugin call it, or override it and have the memo claim silently
   * guard something else. Shapes customise the parts instead — none needs the
   * whole: ::getDefaultSchemaValue(), ::resolveValue(),
   * ::getFieldDefaultValue(), ::isProvidedValueEmpty().
   *
   * @return mixed
   *   The default value.
   *
   * @see ::getDefaultValue()
   */
  private function computeDefaultValue(): mixed {
    $value = $originalValue = $this->resolveValue($this->getDefaultSchemaValue());
    // The provide phase — which provider's value wins — is one collaborator,
    // handed the ordered, already-reset instances and the seed. Why an empty
    // non-claiming producer leaves the seed standing is documented there.
    $value = (new ValueProviderSearch())->search(
      $this->getValueCollection()->getAllowedInstances('default'),
      $value,
      $this,
    );
    if ($fieldDefaultValue = $this->getFieldDefaultValue()) {
      $value = $fieldDefaultValue;
    }
    // The write this method's docblock warns about: publish what the provider
    // search settled on, so the modifier pass below — and anything a modifier
    // asks the shape — reads the value rather than what was there before.
    $this->setFieldItemValue($value, FALSE);
    // Allow providers to modify the final default value. Fetch a fresh list
    // so a claim from the provide loop above does not truncate this one.
    foreach ($this->getValueCollection()->getAllowedInstances('default') as $instance) {
      $value = $instance->alterValue($value, 'default');
      if (!$instance->shouldContinueProcessing()) {
        break;
      }
    }
    // A required prop falls back to the schema example rather than resolving
    // to nothing, so SDC is never handed a missing required prop. The test
    // is isProvidedValueEmpty(), the pipeline's own emptiness contract, not
    // PHP truthiness: under `!$value` a provider that legitimately resolved
    // 0, '0', FALSE or [] had its answer discarded and the component's
    // placeholder rendered in place of real content.
    if ($this->isProvidedValueEmpty($value) && $this->isRequired()) {
      $value = $originalValue;
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function buildDefaultValue(?Attribute $renderAttributes = NULL): mixed {
    $value = $this->getDefaultValue();
    if (is_array($value)) {
      $value = $this->denormalizeValue($value);
    }
    if (is_null($value)) {
      return [];
    }
    $value = $this->adaptValue($value);
    if ($renderAttributes !== NULL) {
      $value = $this->buildRenderValue($value, $renderAttributes);
    }
    return $value;
  }

  /**
   * Get the default value of the field item.
   *
   * @return array
   *   The default value of the field item.
   */
  protected function getDefaultFieldItemValue(): array {
    $fieldItem = clone $this->fieldItem;
    $fieldItem->setValue($this->getDefaultValue());
    return $fieldItem->getValue();
  }

  /**
   * {@inheritDoc}
   */
  public function getDefaultSchemaValue(): mixed {
    return $this->schema['examples'] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function getPreviewPlaceholder(): mixed {
    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function resolveValue(mixed $value): mixed {
    return $value;
  }

  /**
   * Retrieves the default value for a field.
   *
   * This method loads the default value from the field if the current shape
   * is the root entity shape and the scope is 'entity'. It will load the field
   * config component and then get the shape value.
   *
   * @todo It is possible this is only necessary to do when a shape is set as
   * default. This is a somewhat expensive thing to do as it loads reloads the
   * component as its field-level version.
   *
   * @return mixed
   *   The default value of the field.
   */
  protected function getFieldDefaultValue(): mixed {
    $value = [];
    // If the field is not editable, we do not need to load the default
    // value as it is redundant.
    if (!$this->isEditable()) {
      return $value;
    }
    // Load default value from field if this is the root entity shape.
    if ($this->isRoot() && $this->getScope() === 'entity') {
      $component = $this->getComponent();
      if ($component instanceof ComponentEntityInterface) {
        if (!$component->getFieldItem()->getFieldDefinition()->allowCustom()) {
          // If the field does not allow custom, we don't need to load the
          // default value from the field config as it is redundant.
          return $value;
        }
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
  public function setParentValue(mixed $value): self {
    $this->parentValue = $value;
    return $this;
  }

  /**
   * Retrieves the parent override value.
   *
   * @return mixed
   *   The parent value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  protected function getParentValue(): mixed {
    return $this->parentValue;
  }

  /**
   * {@inheritDoc}
   */
  public function setOverrideValue(mixed $value): self {
    // The deadline is ComponentShapeSetupInterface's, not an assertion's.
    // @see ::allowInitPlugins()
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
   * Check if shape has an override value.
   *
   * @return bool
   *   TRUE if shape has an override value, FALSE otherwise.
   */
  protected function hasOverrideValue(): bool {
    return isset($this->parentValue) || isset($this->overrideValue);
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
  public function setFieldItemValue(mixed $value, bool $allowAlter = TRUE): self {
    // If if value is an array but we are not in an array type, we use the first
    // value 0 if set.
    if ($allowAlter) {
      // Set the value so providers can use it.
      $this->fieldItem->setValue($value);
      $instances = $this->getValueCollection()->getAllowedInstances('value');
      // Allow providers to modify the final override value.
      foreach ($instances as $instance) {
        $value = $instance->alterValue($value, 'override');
        if (!$instance->shouldContinueProcessing()) {
          break;
        }
      }
    }
    if (is_array($value) && !$this->isIterable()) {
      $value = $value[0] ?? $value;
    }
    $this->alterFieldItemValue($value);
    $this->fieldItem->setValue($value);
    return $this;
  }

  /**
   * Alters the field item value before it is set.
   *
   * This method can be overridden by subclasses to modify the value before it
   * is set on the field item.
   *
   * @param mixed $value
   *   The value to alter.
   */
  protected function alterFieldItemValue(mixed &$value): void {
    // By default, do nothing.
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
   * Get the widget type options.
   *
   * @return string[]
   *   The widget type options.
   */
  protected function getWidgetTypeOptions(): array {
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

    $form['#attributes']['class'][] = 'neo-alchemist--component-form-shape';
    // The same id is stamped on Attribute-carrying render values in the editor
    // preview, so the preview and the form share one vocabulary — no id
    // munging on either side.
    $form['#attributes']['data-neo-prop'] = $this->id();
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

    if (!$this->isEditable()) {
      // The form method may have locked this form.
      return $form;
    }

    $form['_options'] = [
      '#type' => 'container',
      '#weight' => !empty($form['#title']) ? -10 : 10,
      '#neo_region' => 'legend_end',
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
        $form['#title'] = $this->t('@label <small class="font-normal">(@states)</small>', [
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
      $widget_form_state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
      $form['widget'] = $widget->form($this->getFieldItemList(), $form['widget'], $widget_form_state);
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
    $levels = $trigger['#ajax_level'] ?? -2;
    $element = NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, $levels));
    return $element;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $options = $form_state->getValue('_options') ?? [];
    if (isset($options['default'])) {
      $options['default'] = (int) $options['default'];
    }
    if (isset($options['empty'])) {
      $options['empty'] = (int) $options['empty'];
    }
    if (isset($options['access'])) {
      $options['access'] = (int) $options['access'];
    }
    $this->setOptions($options);
    // Remove options so that they are not processed or stored.
    $form_state->unsetValue('_options');

    // Clear options from user input.
    $userInput = $form_state->getUserInput();
    NestedArray::unsetValue($userInput, array_merge($form['#parents'] ?? [], ['_options']));
    $form_state->setUserInput($userInput);

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
    $stored_values = $submitted_values = $values;
    if (isset($values[$this->getName()]) && ($widget = $this->getWidget())) {
      $massagedValues = $widget->massageFormValues($values[$this->getName()], $form, $form_state);
      // @todo 'values' is checked for checkboxes.
      $massagedValues = $massagedValues[0] ?? $massagedValues['value'] ?? [];
      $fieldItem = clone $this->fieldItem;
      $fieldItem->setValue($massagedValues);
      $fieldItem->preSave();
      $actualValues = $fieldItem->getValue();
      $stored_values = array_intersect_key($actualValues, $fieldItem->getProperties(FALSE));
    }

    foreach ($this->getValueCollection()->getAllowedInstances('form') as $instance) {
      $instance->massageValuesAlter($stored_values, $submitted_values, $original_values, $form, $form_state);
    }
    return $stored_values;
  }

  /**
   * {@inheritDoc}
   */
  public function massageFinalValues(array $values): ?array {
    return $values;
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
  public function getNestedOptionMap(): NestedOptionMap {
    // The one place the shape family delegates to its root. Every other
    // accessor used to carry its own copy of this branch; a view holds the
    // root's store, so re-scoping it is the delegation.
    if (!$this->isRoot()) {
      return $this->getRootShape()->getNestedOptionMap()->forShape($this->id());
    }
    $this->nestedOptionMap ??= new NestedOptionMap();
    return $this->nestedOptionMap->forShape($this->id());
  }

  /**
   * {@inheritDoc}
   */
  public function setOptions(array $options, ?string $id = NULL): self {
    $this->getNestedOptionMap()->forShape($id ?? $this->id())->replaceOwn($options);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getOptions(?string $id = NULL): array {
    return $this->getNestedOptionMap()->forShape($id ?? $this->id())->getOwn();
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
    if (in_array($entityFieldDefinition->getType(), $this->getSupportedFieldTypes())) {
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
      // The field exposes multiple properties. A multi-child object shape can
      // be supplied by such a field when their property data types overlap
      // (e.g. a {value, label} field feeding a {value, label} object). Match by
      // data type here; the exact per-child property mapping is resolved later
      // (see ChildrenShapeBase::getAutoMatchProperties()).
      if ($this instanceof ComponentShapeChildrenPluginInterface) {
        $properties = [];
        foreach ($this->getChildShapes() as $childShape) {
          $properties[$childShape->getName()] = $childShape->getFieldItem()->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
        }
        if ($properties) {
          $needs = array_values(array_unique(array_map(
            fn ($v) => $v->getDataType(),
            $this->contentBearingFieldProperties($entityFieldDefinition, $entityFieldProperties),
          )));
          // A child that is itself an object or array is backed by a 'map'
          // field, which exposes no properties at all. Such a child can never
          // be fed by a single field property, so it contributes nothing here.
          $has = [];
          foreach ($properties as $childProperties) {
            $childProperty = reset($childProperties);
            if ($childProperty instanceof DataDefinitionInterface) {
              $has[] = $childProperty->getDataType();
            }
          }
          return !empty(array_intersect($needs, array_unique($has)));
        }
      }
    }
    return FALSE;
  }

  /**
   * Strips a field's reference pointer, leaving the properties that hold data.
   *
   * An entity reference field exposes `target_id` and `entity`: an id and the
   * thing it points at. Neither carries content — the content is on the
   * referenced entity, one level down.
   *
   * This matters because ::supportsFieldProperties() decides by data-type
   * overlap, and an id is an integer. A plain node reference therefore matched
   * any object shape with a numeric child: `field_related_projects` was offered
   * as a direct source for an `image` prop purely because a node id and the
   * image's `width`/`height` are both integers. Worse than the nonsense
   * offer, it was silently exclusive — MatcherField::matchScalar() takes a
   * supported field and moves on, so the reference was never recursed into and
   * the fields that *are* wanted (`field_related_projects.field_media`) never
   * appeared at all.
   *
   * Only the pointer pair is dropped, so a field that references something and
   * also carries its own data keeps matching on that data: core's `image` and
   * `file` fields still offer alt/title/width/height.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition the properties belong to.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $entityFieldProperties
   *   The field's property definitions.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface[]
   *   The properties that carry data of their own.
   *
   * @see \Drupal\neo_alchemist\MatcherField::matchScalar()
   */
  protected function contentBearingFieldProperties(FieldDefinitionInterface $entityFieldDefinition, array $entityFieldProperties): array {
    // Deliberately the same predicate MatcherField::matchScalar() recurses on,
    // so this only ever steps aside for a reference it will actually descend
    // into. A looser test costs real options: `langcode` also carries a
    // DataReference (its `language` object) but no entity is behind it, and
    // dropping its `value` alongside left the field matching nothing at all.
    $references = array_filter(
      $entityFieldProperties,
      fn ($property) => $property instanceof DataReferenceDefinitionInterface
        && is_a($property->getClass(), EntityReference::class, TRUE),
    );
    if (!$references) {
      return $entityFieldProperties;
    }
    // The id half of the pair is the field's main property — that is what makes
    // it the pointer rather than a value the field happens to store.
    $mainProperty = $entityFieldDefinition->getFieldStorageDefinition()->getMainPropertyName();
    return array_diff_key($entityFieldProperties, $references, array_flip(array_filter([$mainProperty])));
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
  public function isIterable(): bool {
    return $this->getType() === self::ARRAY;
  }

  /**
   * {@inheritDoc}
   */
  public function __clone() {
    unset($this->fieldItem);
    unset($this->fieldItemList);
    unset($this->widget);
    // The option store was two arrays before it was an object, so a clone used
    // to get its own copy for free. Copying it keeps that: a cloned root shape
    // is a separate tree and must not write into the original's options.
    if ($this->nestedOptionMap) {
      $this->nestedOptionMap = clone $this->nestedOptionMap;
    }
  }

}
