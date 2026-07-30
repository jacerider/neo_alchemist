<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentFilterInterface;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentSlotInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Defines the component entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_component",
 *   label = @Translation("Component"),
 *   label_collection = @Translation("Components"),
 *   label_singular = @Translation("component"),
 *   label_plural = @Translation("components"),
 *   label_count = @PluralTranslation(
 *     singular = "@count component",
 *     plural = "@count components",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\neo_alchemist\ComponentListBuilder",
 *     "storage" = "Drupal\neo_alchemist\ComponentStorage",
 *     "access" = "Drupal\neo_alchemist\ComponentAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\neo_alchemist\Form\ComponentForm",
 *       "edit" = "Drupal\neo_alchemist\Form\ComponentForm",
 *       "clone" = "Drupal\neo_alchemist\Form\ComponentCloneForm",
 *       "aggregate" = "Drupal\neo_alchemist\Form\ComponentAggregateForm",
 *       "prop" = "Drupal\neo_alchemist\Form\ComponentPropForm",
 *       "slot" = "Drupal\neo_alchemist\Form\ComponentSlotForm",
 *       "filter" = "Drupal\neo_alchemist\Form\ComponentFilterForm",
 *       "access" = "Drupal\neo_alchemist\Form\ComponentAccessForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *       "manage" = "Drupal\neo_alchemist\Form\ComponentManageForm",
 *       "style" = "Drupal\neo_alchemist\Form\ComponentStyleForm",
 *       "preview_value" = "Drupal\neo_alchemist\Form\SdcPreviewForm",
 *       "preview_context" = "Drupal\neo_alchemist\Form\SdcPreviewContextForm",
 *     },
 *   },
 *   config_prefix = "neo_component",
 *   admin_permission = "administer neo_alchemist",
 *   links = {
 *     "collection" = "/admin/config/neo/alchemist",
 *     "add-form" = "/admin/config/neo/alchemist/add/{component}",
 *     "edit-form" = "/admin/config/neo/alchemist/{neo_component}/edit",
 *     "aggregate-form" = "/admin/config/neo/alchemist/{neo_component}/aggregate",
 *     "edit-prop-form" = "/admin/config/neo/alchemist/{neo_component}/prop/{prop}",
 *     "edit-slot-form" = "/admin/config/neo/alchemist/{neo_component}/slot/{slot}",
 *     "add-filter-form" = "/admin/config/neo/alchemist/{neo_component}/filter/add",
 *     "edit-filter-form" = "/admin/config/neo/alchemist/{neo_component}/filter/{uuid}",
 *     "add-access-form" = "/admin/config/neo/alchemist/{neo_component}/access/add",
 *     "edit-access-form" = "/admin/config/neo/alchemist/{neo_component}/access/{uuid}",
 *     "clone-form" = "/admin/config/neo/alchemist/{neo_component}/clone",
 *     "delete-form" = "/admin/config/neo/alchemist/{neo_component}/delete",
 *     "canonical" = "/admin/config/neo/alchemist/{neo_component}",
 *     "preview" = "/admin/config/neo/alchemist/{neo_component}/preview",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "component" = "component",
 *     "label" = "label",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "group",
 *     "expression",
 *     "schema",
 *     "component",
 *     "aggregate",
 *     "size",
 *     "thumbnail",
 *     "settings",
 *     "target_entity_type",
 *     "target_entity_bundle",
 *   },
 * )
 */
class Component extends ConfigEntityBase implements ComponentInterface {

  use IconTrait;

  /**
   * The component ID.
   *
   * @var string
   */
  protected string $id;

  /**
   * The component label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The component description.
   *
   * @var string
   */
  protected string $description;

  /**
   * The component group.
   *
   * @var string
   */
  protected string $group = 'general';

  /**
   * The component expression.
   *
   * @var string
   */
  protected string $expression = '';

  /**
   * The SDS component.
   *
   * @var string
   */
  protected string $component;

  /**
   * Indicates whether the component is an aggregate.
   *
   * @var bool
   */
  protected bool $aggregate = FALSE;

  /**
   * The size.
   *
   * @var string|null
   */
  protected ?string $size;

  /**
   * The thumbnail.
   *
   * @var string|null
   */
  protected ?string $thumbnail;

  /**
   * The settings.
   *
   * @var array|null
   */
  protected ?array $settings;

  /**
   * The target entity type.
   *
   * @var string|null
   */
  protected ?string $target_entity_type;

  /**
   * The target entity bundle.
   *
   * @var string|null
   */
  protected ?string $target_entity_bundle;

  /**
   * The scope of the component.
   *
   * Can be 'config', 'field' or 'entity'.
   *
   * @var string|null
   */
  protected string $scope = 'config';

  /**
   * The preview status of the component.
   *
   * @var bool
   */
  protected bool $preview = FALSE;

  /**
   * The instance preview status of the component.
   *
   * @var bool
   */
  protected bool $instancePreview = FALSE;

  /**
   * The rebuilding status of the component.
   *
   * Will be try when the component is rebuilding without being saved.
   *
   * @var bool
   */
  protected bool $rebuilding = FALSE;

  /**
   * The cached SDC component plugin.
   *
   * @var \Drupal\Core\Plugin\Component|null|false
   */
  protected ComponentPlugin|null|false $componentPlugin = FALSE;

  /**
   * The prop shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected array $propShapes;

  /**
   * The shape contexts.
   *
   * @var array
   */
  protected array $propShapeContexts = [];

  /**
   * Required props left empty in preview, keyed by prop name.
   *
   * Populated by getPropValues() for shapes that were required, empty, and had
   * no getPreviewPlaceholder() to stand in. Only ever populated in preview.
   *
   * @var (string|\Drupal\Component\Render\MarkupInterface)[]
   */
  protected array $unsatisfiedRequiredProps = [];

  /**
   * The slots.
   *
   * @var \Drupal\neo_alchemist\ComponentSlot[]
   */
  protected array $slots;

  /**
   * The filters.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterInterface[]
   */
  protected array $filters;

  /**
   * The component access.
   *
   * @var array
   */
  protected array $access;

  /**
   * The cacheable metadata.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cacheableMetadata;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(): string {
    return $this->group;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupLabel(): MarkupInterface|string {
    $definition = $this->getGroupDefinition();
    return $definition['label'] ?? $this->getGroup();
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupDescription(): MarkupInterface|string {
    $definition = $this->getGroupDefinition();
    return $definition['description'] ?? '';
  }

  /**
   * Gets the group plugin definition for this component's group.
   *
   * @return array
   *   The group plugin definition, or an empty array if not found.
   */
  protected function getGroupDefinition(): array {
    $groups = \Drupal::service('plugin.manager.neo_component_group')->getDefinitions();
    return $groups[$this->getGroup()] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getExpression(): string {
    return $this->expression;
  }

  /**
   * Generates a combined expression string from the component's shapes.
   *
   * This method retrieves all shapes associated with the component, extracts
   * their individual expressions, sorts them, and then concatenates them into
   * a single string separated by the '~' character.
   *
   * @return string
   *   The combined expression string.
   */
  public function generateExpression(): string {
    $expressions = array_map(fn($shape) => $shape->getExpression(), $this->getPropShapes());
    ksort($expressions);
    return implode('~', $expressions);
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool {
    return (bool) $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function isAggregate(): bool {
    return $this->aggregate;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentId(): string {
    return $this->component;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(): ?ComponentPlugin {
    if ($this->componentPlugin === FALSE) {
      /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
      $manager = \Drupal::service('plugin.manager.sdc');
      if (!$manager->hasDefinition($this->getComponentId())) {
        $this->componentPlugin = NULL;
      }
      else {
        $this->componentPlugin = $manager->find($this->getComponentId());
      }
    }
    return $this->componentPlugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSchema(): ?array {
    return $this->getComponent()?->metadata->schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSlots(): array {
    return $this->getComponent()?->metadata->slots ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSize(): ?string {
    return $this->size ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getThumbnailId(): ?string {
    return $this->thumbnail ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultThumbnail(): ?string {
    return $this->getComponent()?->metadata->getThumbnailPath();
  }

  /**
   * {@inheritdoc}
   */
  public function getThumbnail(): ?string {
    if ($thumbnailId = $this->getThumbnailId()) {
      /** @var \Drupal\neo_config_file\ConfigFileInterface|null $configFile */
      $configFile = $this->entityTypeManager()->getStorage('neo_config_file')->load($thumbnailId);
      $file = $configFile?->getFile();
      if ($file) {
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        // Append a cache-busting token so a re-captured thumbnail — saved to
        // the same filename/URI as the one it replaces — is not served stale
        // from the browser cache. The changed time updates when the bytes do.
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $file->getChangedTime();
      }
    }
    if ($defaultThumbnail = $this->getDefaultThumbnail()) {
      return '/' . $defaultThumbnail;
    }
    return '/' . \Drupal::service('extension.list.module')->getPath('neo_alchemist') . '/images/thumbnail.jpg';
  }

  /**
   * {@inheritdoc}
   */
  public function getPath(): string {
    return $this->getComponent()?->metadata->path ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getScope(): string {
    return $this->scope;
  }

  /**
   * {@inheritdoc}
   */
  public function setPreview(bool $preview): self {
    $this->preview = $preview;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isPreview(): bool {
    return $this->preview;
  }

  /**
   * {@inheritdoc}
   */
  public function setInstancePreview(bool $instancePreview): self {
    $this->instancePreview = $instancePreview;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isInstancePreview(): bool {
    return $this->instancePreview;
  }

  /**
   * {@inheritdoc}
   */
  public function isComponentPreview(): bool {
    $routeName = \Drupal::routeMatch()->getRouteName();
    return in_array($routeName, [
      'entity.neo_component.preview',
      'neo_alchemist.sdc_preview_frame',
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isManagePreview(): bool {
    return $this->isPreview() && !$this->isComponentPreview() && !$this->isInstancePreview();
  }

  /**
   * {@inheritdoc}
   */
  public function setRebuilding(bool $rebuilding): self {
    $this->rebuilding = $rebuilding;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isRebuilding() {
    return $this->rebuilding;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings(): array {
    return $this->settings ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSetting(string $key, $default = NULL): mixed {
    return $this->settings[$key] ?? $default;
  }

  /**
   * {@inheritdoc}
   */
  public function setSetting(string $key, $value): self {
    // Reload prop shapes.
    unset($this->propShapes);
    $this->settings[$key] = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDefinition(): array {
    /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.sdc');
    return $manager->getDefinition($this->getComponentId());
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): ?string {
    return $this->get('target_entity_type');
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeDefinition(): ?EntityTypeInterface {
    $targetEntityType = $this->getTargetEntityTypeId();
    return $targetEntityType ? \Drupal::entityTypeManager()->getDefinition($targetEntityType) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityBundle(): ?string {
    return $this->get('target_entity_bundle');
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntity(): ContentEntityInterface {
    $entity = $this->getTargetPreviewEntity();
    if (!$entity && $this->getTargetEntityTypeId()) {
      $entity = $this->createTargetPlaceholderEntity();
    }
    if (!$entity) {
      $entityTypeManager = \Drupal::entityTypeManager();
      $entity = $entityTypeManager->getStorage('node')->create([
        'type' => 'page',
      ]);
    }
    return $entity;
  }

  /**
   * Create a placeholder entity given an entity id.
   *
   * @param string|null $entityTypeId
   *   The entity type id.
   * @param string|null $bundle
   *   The bundle id.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity.
   */
  protected function createTargetPlaceholderEntity(?string $entityTypeId = NULL, ?string $bundle = NULL): ?ContentEntityInterface {
    $entity = NULL;
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityTypeId = $entityTypeId ?? $this->getTargetEntityTypeId();
    $entityType = $entityTypeManager->getDefinition($entityTypeId);
    $bundleKey = $entityType->getKey('bundle');
    if ($bundleKey) {
      $bundle = $bundle ?? $this->getTargetEntityBundle();
      if (!$bundle) {
        // When we have no bundle, we need to get the first one.
        $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entityTypeId);
        if ($bundles) {
          $bundle = key($bundles);
        }
      }
      if ($bundle) {
        $entity = $entityTypeManager->getStorage($entityTypeId)->create([
          $bundleKey => $bundle,
        ]);
      }
    }
    else {
      $entity = $entityTypeManager->getStorage($entityTypeId)->create();
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetPreviewEntity(string $entityId): bool {
    if (!$this->isNew() && ($entityTypeId = $this->getTargetEntityTypeId())) {
      $entity = \Drupal::entityTypeManager()->getStorage($entityTypeId)->load($entityId);
      if ($entity) {
        \Drupal::state()->set('neo_alchemist.' . $this->id() . '.preview_entity', $entity->id());
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetPreviewEntity(): ?ContentEntityInterface {
    $entityId = \Drupal::state()->get('neo_alchemist.' . $this->id() . '.preview_entity');
    $entityTypeId = $this->getTargetEntityTypeId();
    if ($entityId && $entityTypeId) {
      return \Drupal::entityTypeManager()->getStorage($entityTypeId)->load($entityId);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPreviewStyles(): bool {
    return !empty($this->getPreviewStyles());
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviewStyles(): array {
    $cache = \Drupal::cache();
    if ($data = $cache->get('neo_alchemist.' . $this->id() . '.preview_style')) {
      return $data->data;
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPreviewStyle(string $shapeId, string $shapeValue): self {
    $cache = \Drupal::cache();
    $styles = $this->getPreviewStyles();
    $styles[$shapeId] = $shapeValue;
    $cache->set('neo_alchemist.' . $this->id() . '.preview_style', $styles, strtotime('+10 minutes'));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviewStyle(string $shapeId): ?string {
    return $this->getPreviewStyles()[$shapeId] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resetPreviewStyle(): self {
    \Drupal::cache()->delete('neo_alchemist.' . $this->id() . '.preview_style');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPreviewValues(): bool {
    return !empty($this->getPreviewValues());
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviewValues(): array {
    $cache = \Drupal::cache();
    if ($data = $cache->get('neo_alchemist.' . $this->id() . '.preview_values')) {
      return $data->data;
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPreviewValues(array $values): self {
    // Reload prop shapes so the new overrides are reflected immediately within
    // the current request (e.g. form rebuilds).
    unset($this->propShapes);
    \Drupal::cache()->set('neo_alchemist.' . $this->id() . '.preview_values', $values, strtotime('+1 hour'));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function resetPreviewValues(): self {
    unset($this->propShapes);
    \Drupal::cache()->delete('neo_alchemist.' . $this->id() . '.preview_values');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviewContext(): array {
    $cache = \Drupal::cache();
    if ($data = $cache->get('neo_alchemist.' . $this->id() . '.preview_context')) {
      return $data->data;
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPreviewContext(?string $above, ?string $below): self {
    \Drupal::cache()->set('neo_alchemist.' . $this->id() . '.preview_context', [
      'above' => $above ?: NULL,
      'below' => $below ?: NULL,
    ], strtotime('+1 hour'));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function resetPreviewContext(): self {
    \Drupal::cache()->delete('neo_alchemist.' . $this->id() . '.preview_context');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getValues(): array {
    // When previewing a config-scope component (e.g. the SDC preview
    // workspace), return any cache-backed preview-value overrides so the shapes
    // are seeded with the values a developer is editing. These overrides never
    // persist to configuration.
    if ($this->isPreview() && $this->getScope() === 'config') {
      return $this->getPreviewValues();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    if (!isset($this->cacheableMetadata)) {
      $this->cacheableMetadata = new CacheableMetadata();
      $this->cacheableMetadata->addCacheableDependency($this);
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
   * {@inheritdoc}
   */
  public function getValue($key, mixed $default = NULL): mixed {
    $exists = NULL;
    $values = $this->getValues();
    $value = NestedArray::getValue($values, (array) $key, $exists);
    return $exists ? $value : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function loadPropShapes(array $schema): array {
    /** @var \Drupal\neo_alchemist\ComponentShapePluginManager $manager */
    $manager = \Drupal::service('plugin.manager.neo_component_shape');
    // Get shapes and initialize them.
    if ($this->isAggregate()) {
      $schema = $this->getAggregateSchema($schema);
    }
    return array_map(fn ($v) => $v->init(), $manager->getInstancesFromSchema($schema, $this, $this->getSetting('props', []), $this->getValues()));
  }

  /**
   * {@inheritdoc}
   */
  public function hasPropShapeWithPlugin(string $pluginId): bool {
    foreach ($this->getPropShapes() as $shape) {
      if ($shape->hasPlugin($pluginId)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get the aggregate schema.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The shapes.
   */
  protected function getAggregateSchema(array $schema): array {
    return [
      'type' => 'object',
      'properties' => [
        '_aggregate' => [
          'title' => 'Aggregate',
        ] + $schema,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapes(?array $schema = NULL): array {
    if (!isset($this->propShapes) || $schema !== NULL) {
      $propShapes = [];
      if ($component = $this->getComponent()) {
        $propShapes = $this->loadPropShapes($schema ?? $component->metadata->schema);
      }
      if ($schema) {
        return $propShapes;
      }
      $this->propShapes = $propShapes;
    }
    return $this->propShapes;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShape(string $propName): ?ComponentShapePluginInterface {
    return $this->getPropShapes()[$propName] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropValues(): array {
    $values = [];
    $attributes = new Attribute();
    $cacheableMetadata = $this->getCacheableMetadata();
    $this->unsatisfiedRequiredProps = [];
    foreach ($this->getPropShapes() as $shapeId => $shape) {
      if (!$shape->isActive()) {
        // Skip inactive shapes.
        continue;
      }

      // We call getPropValue() instead of getValue() so that shapes have
      // the opportunity to modify the value before it is returned in a way
      // that may not be compatible with the field item but is still valid
      // for SDC.
      $value = $shape->getPropValue($attributes);

      // A required prop with no value is dropped below like any other empty
      // prop, and SDC then fails its required-prop schema assertion — taking
      // down the whole render rather than just the prop. In a preview there is
      // no content builder to have filled the prop in, so give the shape a
      // chance to stand in something renderable. This is preview-only: a live
      // render is left exactly as it was, and content builders are still held
      // to the requirement by the shape's own form validation.
      if ($this->isPreview() && $shape->isRequired() && $this->isPropValueEmpty($value)) {
        $value = $shape->getPreviewPlaceholder();
        if ($this->isPropValueEmpty($value)) {
          // The shape had nothing to stand in. Record it so toRenderable() can
          // render a notice naming the prop instead of letting SDC fail.
          $this->unsatisfiedRequiredProps[$shapeId] = $shape->getTitle();
        }
      }

      // Always add the shape cacheable metadata to the component. This must
      // happen after getPropValue() and getPreviewPlaceholder() so dependencies
      // added while building the value (e.g. a value provider calling
      // addCacheableDependency()) are included; merging earlier would snapshot
      // the metadata before they exist.
      $cacheableMetadata->addCacheableDependency($shape->getCacheableMetadata());

      if ($this->isPropValueEmpty($value)) {
        continue;
      }
      $values[$shapeId] = $value;
    }
    if ($this->isAggregate()) {
      $values = $values['_aggregate'] ?? [];
    }

    $values['attributes'] = $attributes;
    return $values;
  }

  /**
   * Determines whether a prop value is empty and should not be passed to SDC.
   *
   * FALSE is a meaningful value for a boolean prop, and 0/0.0/'0' are
   * meaningful values for number and string props, so none of them are
   * considered empty — aligning this render-boundary check with the shape
   * layer's isProvidedValueEmpty() contract. (An empty string and an empty
   * array remain empty: a scalar prop resolving to '' has nothing to say and
   * dropping it lets twig |default() filters apply.)
   *
   * @param mixed $value
   *   The prop value.
   *
   * @return bool
   *   TRUE if the value is empty.
   */
  protected function isPropValueEmpty(mixed $value): bool {
    return !is_bool($value)
      && $value !== 0
      && $value !== 0.0
      && $value !== '0'
      && empty($value);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapesAll(?array $shapes = NULL, ?bool $includeDeltas = FALSE): array {
    $allShapes = [];
    $shapes = is_null($shapes) ? $this->getPropShapes() : $shapes;
    foreach ($shapes as $shape) {
      $allShapes += $shape->getAllShapes(TRUE, $includeDeltas);
    }
    return $allShapes;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllPropShapeSettings(): array {
    return $this->getSettings()['props'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapeSettings(string $propName): array {
    return $this->getAllPropShapeSettings()[$propName] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPropShapeSettings(ComponentShapePluginInterface $shape): self {
    if ($this->isAggregate() && $shape->getName() !== '_aggregate') {
      // Do not save individual shapes when aggregating.
      return $this;
    }
    unset($this->propShapes);
    $expanded = $shape->getExpanded();
    $settings = [
      'prop' => $shape->getName(),
      'ref' => $shape->getRef(),
      'field_type' => $shape->getFieldType(),
      'expanded' => $expanded,
      'active' => $shape->isActive(),
      'editable' => $shape->getEditable(),
      'required' => $shape->isRequired(),
    ];
    foreach ($shape->getAllShapes(TRUE) as $childShape) {
      $collection = $childShape->getValueCollection();
      $childShape->onUpdate();
      foreach ($collection->getInstances() as $instanceId => $instance) {
        $instanceSettings = $instance->getConfiguration();
        if ($collection->getStatus($instanceId)) {
          if (!$childShape->allowConfigurablePlugins()) {
            continue;
          }
          $id = $childShape->id();
          $settings['plugins'][$id][$instance->getPluginId()] = [
            'id' => $instance->getPluginId(),
            'settings' => $instanceSettings,
          ];
        }
      }
    }
    $this->settings['props'][$shape->getName()] = $settings;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPropShapeContext(string $type, ComponentShapePluginInterface $shape, mixed $value): self {
    $this->propShapeContexts[$type][$shape->id()] = [
      'shape' => $shape,
      'value' => $value,
    ];
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapeContexts(string $type): array {
    // Always load prop shapes so that contexts are applied.
    $this->getPropShapes();
    return $this->propShapeContexts[$type] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSlots(): array {
    if (!isset($this->slots)) {
      $this->slots = [];
      /** @var \Drupal\neo_alchemist\ComponentSlotFactory $factory */
      $factory = \Drupal::service('neo_component.slot.factory');
      foreach ($this->getComponentSlots() as $slotName => $schema) {
        $this->slots[$slotName] = $factory->get($this, $slotName, $schema, $this->settings['slots'][$slotName] ?? []);
      }
    }
    return $this->slots;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlot(string $slotName): ?ComponentSlotInterface {
    return $this->getSlots()[$slotName] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotSettings(ComponentSlotInterface $slot): array {
    return $this->settings['slots'][$slot->getName()] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlotSettings(ComponentSlotInterface $slot, array $settings): self {
    $this->settings['slots'][$slot->getName()] = $settings;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setFilter(ComponentFilterInterface $filter): ComponentFilterInterface {
    unset($this->filters);
    $uuid = $filter->isNew() ? $this->uuidGenerator()->generate() : $filter->uuid();
    $this->settings['filters'][$uuid] = $filter->toArray();
    return $this->getFilter($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getFilter(string $uuid): ?ComponentFilterInterface {
    return $this->getFilters()[$uuid] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFilter(string $uuid): self {
    unset($this->settings['filters'][$uuid]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(?string $pluginId = NULL): array {
    if (!isset($this->filters)) {
      $this->filters = [];
      if (!empty($this->settings['filters'])) {
        $factory = \Drupal::service('neo_component.filter.factory');
        $values = $this->getValues();
        foreach ($this->settings['filters'] as $uuid => $data) {
          $this->filters[$uuid] = $factory->get($this, ['uuid' => $uuid] + $data);
          if ($this->filters[$uuid]->isEditable()) {
            if (isset($values['filters'][$uuid]['value']) && $values['filters'][$uuid]['value'] !== NULL) {
              $this->filters[$uuid]->setOverrideValue($values['filters'][$uuid]['value']);
            }
          }
        }
      }
    }
    if ($pluginId) {
      return array_filter($this->filters, fn ($filter) => $filter->getPluginId() === $pluginId);
    }
    return $this->filters;
  }

  /**
   * Check access plugins for access.
   *
   * @param string $operation
   *   The operation to check access for, e.g., 'view', 'update', 'manage'.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result object indicating whether the operation is allowed.
   */
  protected function checkAccess(string $operation, ?AccountInterface $account = NULL): AccessResultInterface {
    if (!in_array($operation, array_keys(ComponentAccessInterface::OPS))) {
      return AccessResult::neutral();
    }
    // Fold every consulted plugin's cacheability into whatever is returned.
    // The render call sites capture the result's metadata; dropping a
    // plugin's contribution (e.g. PropValueAccess's resolved-value
    // dependencies on the neutral path) leaves a component cached as
    // visible/hidden forever once the condition that decided it changes.
    // Deliberately a manual fold rather than orIf(): core's neutral∧neutral
    // combination drops the second operand's cacheability.
    $cacheability = new CacheableMetadata();
    foreach ($this->getAccessInstances() as $accessInstance) {
      $access = $accessInstance->access($operation, $account ?? \Drupal::currentUser());
      // If any access plugin denies access, we deny access to the component
      // instance — carrying the cacheability of every plugin consulted
      // before it.
      if ($access->isForbidden()) {
        if ($access instanceof RefinableCacheableDependencyInterface) {
          $access->addCacheableDependency($cacheability);
        }
        return $access;
      }
      $cacheability->addCacheableDependency($access);
    }
    return AccessResult::neutral()->addCacheableDependency($cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function setAccess(ComponentAccessInterface $access): ComponentAccessInterface {
    unset($this->access);
    $uuid = $access->isNew() ? $this->uuidGenerator()->generate() : $access->uuid();
    $this->settings['access'][$uuid] = $access->toArray();
    return $this->getAccess($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getAccess(string $uuid): ?ComponentAccessInterface {
    return $this->getAccessInstances()[$uuid] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAccess(string $uuid): self {
    unset($this->settings['access'][$uuid]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessInstances(): array {
    if (!isset($this->access)) {
      $this->access = [];
      if (!empty($this->settings['access'])) {
        $factory = \Drupal::service('neo_component.access.factory');
        foreach ($this->settings['access'] as $uuid => $data) {
          $this->access[$uuid] = $factory->get($this, ['uuid' => $uuid] + $data);
        }
      }
    }
    return $this->access;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    if (!isset($this->original)) {
      $newExpression = $this->generateExpression();
      $this->set('schema', Json::encode($this->getComponentSchema()));
      $this->set('expression', $newExpression);
      $rootShapes = $this->getPropShapes();
      foreach ($rootShapes as $shape) {
        $shape->onAdd();
        foreach ($shape->getPlugins() as $id => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            $shape->onPluginAdd($pluginType);
          }
        }
      }
      // Process all props and store the settings.
      $this->setSetting('props', []);
      foreach ($rootShapes as $shape) {
        $this->setPropShapeSettings($shape);
      }
      $this->setSetting('props', $this->getAllPropShapeSettings());
    }
    else {
      /** @var \Drupal\neo_alchemist\ComponentInterface $original */
      $original = $this->original;
      $currentSchema = Json::decode($this->get('schema')) ?? [];
      $currentRootShapes = $original->getPropShapes($currentSchema);
      $currentShapes = $original->getPropShapesAll($currentRootShapes);
      ksort($currentShapes);
      $newRootShapes = $this->getPropShapes();
      $newShapes = $this->getPropShapesAll($newRootShapes);
      ksort($newShapes);

      $currentShapesWithRef = [];
      foreach ($currentShapes as $id => $shape) {
        $currentShapesWithRef[$id . ':' . $shape->getRef()] = $shape;
      }
      $newShapesWithRef = [];
      foreach ($newShapes as $id => $shape) {
        $newShapesWithRef[$id . ':' . $shape->getRef()] = $shape;
      }

      $addedShapes = array_diff_key($newShapesWithRef, $currentShapesWithRef);
      $removedShapes = array_diff_key($currentShapesWithRef, $newShapesWithRef);
      $sameShapes = array_intersect_key($currentShapesWithRef, $newShapesWithRef);

      // Run updates on any non-added/removed shapes.
      foreach ($newRootShapes as $shape) {
        if (isset($sameShapes[$shape->id() . ':' . $shape->getRef()])) {
          $this->setPropShapeSettings($shape);
        }
      }

      // If a prop has been added/removed/type changed, we need to fire off
      // events and store the changes.
      $newExpression = $this->generateExpression();
      $currentExpression = $this->getExpression();

      if ($currentExpression !== $newExpression) {
        // We add the shape ref to the key so we can find instances where the
        // prop name is the same but the shape is different.
        foreach ($addedShapes as $shape) {
          $shape->onAdd();
        }
        foreach ($removedShapes as $shape) {
          $shape->onRemove();
        }

        // Process all props and store the settings.
        $this->setSetting('props', []);
        foreach ($newRootShapes as $shape) {
          $this->setPropShapeSettings($shape);
        }
        $this->setSetting('props', $this->getAllPropShapeSettings());

        // Update schema and expression.
        $this->set('schema', Json::encode($this->getComponentSchema()));
        $this->set('expression', $newExpression);
      }

      // Find all prop plugins changes and fire off add/remove events.
      foreach ($currentRootShapes + $newRootShapes as $id => $shape) {
        $currentPlugins = [];
        if (isset($currentRootShapes[$id])) {
          $currentPlugins = $currentRootShapes[$id]->getPlugins();
        }
        $newPlugins = [];
        if (isset($newRootShapes[$id])) {
          $newPlugins = $newRootShapes[$id]->getPlugins();
        }
        foreach ($currentPlugins as $id => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            if (isset($currentShapes[$id]) && !isset($newPlugins[$id][$pluginType])) {
              $currentShapes[$id]->onPluginRemove($pluginType);
            }
          }
        }
        foreach ($newPlugins as $id => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            if (isset($newShapes[$id]) && !isset($currentPlugins[$id][$pluginType])) {
              $newShapes[$id]->onPluginAdd($pluginType);
            }
          }
        }
      }
    }
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    /** @var \Drupal\neo_alchemist\ComponentInterface[] $entities */
    foreach ($entities as $entity) {
      foreach ($entity->getPropShapes() as $shape) {
        $shape->onRemove();
        foreach ($shape->getPlugins() as $id => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            $shape->onPluginRemove($pluginType);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    if ($this->isNew()) {
      $this->set('id', $this->getUniqueId());
    }
    return parent::save();
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable($isFirst = NULL, $isLast = NULL): array {
    if ($this->isPreview()) {
      // When rendering as preview, we need to set the target entity so that
      // shapes and slots that utilize route parameters will have something
      // to work with.
      $entity = $this->getTargetEntity();
      $parameters = \Drupal::routeMatch()->getParameters();
      if (!$parameters->has($entity->getEntityTypeId())) {
        $parameters->set($entity->getEntityTypeId(), $entity);
      }
    }

    // Populates $this->unsatisfiedRequiredProps as a side effect, so it must be
    // called before that is read below.
    $props = $this->getPropValues();

    if ($this->unsatisfiedRequiredProps) {
      // Preview-only. Rendering the component would hand SDC a missing required
      // prop and fail its schema assertion, replacing the page with a stack
      // trace. Name the prop instead — the frontend dev sees what is missing,
      // and the requirement itself is untouched.
      $build = $this->buildUnsatisfiedRequiredPropsNotice();
    }
    else {
      $build = [
        '#type' => 'component',
        '#component' => $this->getComponentId(),
        '#props' => $props,
      ];
      // Add unique identifiers to props.
      $build['#props']['neoId'] = $this->id();
      $build['#props']['neoUuid'] = $this->uuid();
      $build['#props']['neoIsPreview'] = $this->isPreview();
      if ($slots = $this->getSlots()) {
        $build['#slots'] = array_filter(array_map(fn($slot) => $slot->toRenderable(), $slots));
      }
    }

    $cacheableMetadata = $this->getCacheableMetadata();

    // Let modules and themes alter a component's build and cacheability. Two
    // hooks fire: the generic hook_neo_component_build_alter() and a
    // component-specific hook_neo_component_build_BASE_ID_alter(), where
    // BASE_ID is the component id with non-alphanumerics replaced by
    // underscores (e.g. "front:bottom" => "front_bottom"). Prefer the specific
    // variant — it runs no code for any other component. This method is only
    // reached on a cache miss (the component build is not itself
    // render-cached), so the per-component cost is one alter() pass over
    // cached, usually-empty listener lists. Alter cacheability via
    // $cacheableMetadata (applied below), not $build['#cache'], which applyTo()
    // overwrites. The raw #props are exposed here, before any preview wrapper.
    //
    // Module and active-theme alter invocations are separate: ModuleHandler
    // runs module implementations, ThemeManager runs the active theme chain's.
    // Both are invoked (modules first, theme last, matching Drupal convention)
    // because components are authored in themes — a theme's own .theme is the
    // natural home for a component-specific alter.
    //
    // In preview, $build may be the unsatisfied-required-props notice rather
    // than the component, so it has no #props to read or alter.
    $hooks = [
      'neo_component_build',
      'neo_component_build_' . preg_replace('/[^a-z0-9_]+/', '_', $this->getComponentId()),
    ];
    $this->moduleHandler()->alter($hooks, $build, $cacheableMetadata, $this);
    \Drupal::theme()->alter($hooks, $build, $cacheableMetadata, $this);

    if ($this->isManagePreview()) {
      $build = $this->prepareRenderableForPreview($build, $isFirst, $isLast);
    }

    $cacheableMetadata->applyTo($build);

    return $build;
  }

  /**
   * Builds the notice shown in place of a component missing required props.
   *
   * Reached only in preview, and only for required props that were empty and
   * whose shape had no getPreviewPlaceholder() to offer — a `media` prop, for
   * instance, when the site has no media of a supported type to borrow. The
   * audience is the frontend dev looking at the component preview, so it names
   * the props and points at the two ways to resolve it.
   *
   * Styling is inline rather than themed: this renders inside whatever theme
   * the preview uses, and a dev notice should not depend on a Tailwind rebuild
   * to be legible.
   *
   * @return array
   *   The notice render array.
   */
  protected function buildUnsatisfiedRequiredPropsNotice(): array {
    return [
      '#type' => 'inline_template',
      '#template' => <<<'TWIG'
        <div{{ attributes }} style="margin:1rem;padding:1rem 1.25rem;border:1px dashed #b45309;border-radius:.375rem;background:#fffbeb;color:#78350f;font-family:system-ui,sans-serif;font-size:.875rem;line-height:1.5;">
          <strong style="display:block;margin-bottom:.5rem;">{{ label }} cannot be previewed</strong>
          <p style="margin:0 0 .5rem;">
            {{ props|length > 1 ? 'These required props have' : 'This required prop has' }}
            no value, and no example to preview with:
            <strong>{{ props|join(', ') }}</strong>.
          </p>
          <p style="margin:0;">
            Content builders are required to fill {{ props|length > 1 ? 'these in' : 'this in' }},
            so there is no default to fall back on here. To preview the component,
            either create content of the type the prop accepts, or give the prop
            a <code>getPreviewPlaceholder()</code> in its shape plugin.
          </p>
        </div>
      TWIG,
      '#context' => [
        'label' => $this->label(),
        'props' => array_map('strval', $this->unsatisfiedRequiredProps),
        // Where prepareRenderableForPreview() hangs the editor chrome, so the
        // notice stays selectable in the component tree like any component.
        'attributes' => new Attribute(),
      ],
    ];
  }

  /**
   * Prepares a component for preview rendering.
   *
   * @param array $build
   *   The render array build.
   * @param bool|null $isFirst
   *   Whether this is the first component in a list, or NULL if the caller
   *   does not know its position.
   * @param bool|null $isLast
   *   Whether this is the last component in a list, or NULL if the caller does
   *   not know its position.
   *
   * @return array
   *   The modified render array build.
   */
  private function prepareRenderableForPreview(array $build, $isFirst = NULL, $isLast = NULL): array {
    $alerts = [];
    $warnings = [];
    if (!$this->isPublished()) {
      $alerts[] = $this->adminIcon('Disabled', 'ban');
    }
    if ($this->getAccessInstances()) {
      $warnings[] = $this->adminIcon('Limited Access', 'lock');
    }
    if ($this instanceof ComponentInstanceInterface && $this->isInherited()) {
      $warnings[] = $this->adminIcon('Global', 'lock');
    }
    $data = [
      'label' => $this->label(),
      'alerts' => $alerts,
      'warnings' => $warnings,
      'inherited' => $this instanceof ComponentInstanceInterface && $this->isInherited(),
      'ops' => [
        'edit' => $this->access('update'),
        'delete' => $this->access('delete'),
        'sort' => $this->access('sort'),
        'clone' => $this->access('clone'),
        'add-before' => $this->access('create'),
        'add-after' => $this->access('create'),
        // Strict comparison so an unknown position (NULL) withholds the move
        // rather than offering it. A caller that forgets to say where the
        // component sits should fail closed: an offered Move Up on the first
        // component is a button that silently does nothing.
        'move-up' => $this->access('sort') && $isFirst === FALSE,
        'move-down' => $this->access('sort') && $isLast === FALSE,
      ],
    ];

    // Normally the component's own attributes prop. When a required prop could
    // not be satisfied in preview, $build is the notice instead, which carries
    // its attributes in #context — the editor chrome still belongs on it so the
    // component stays selectable.
    $attributes = $build['#props']['attributes'] ?? $build['#context']['attributes'] ?? NULL;
    if ($attributes instanceof Attribute) {
      $attributes->addClass(!$this->isPublished() ? 'opacity-50' : '');
      $attributes->setAttribute('data-component-uuid', $this->uuid());
      $attributes->setAttribute('data-component', Json::encode($data));
    }
    return $build;
  }

  /**
   * Generates a unique machine name for a component.
   *
   * @return string
   *   Returns the unique name.
   */
  public function getUniqueId() {
    $parts = explode(':', $this->getComponentId());
    $suggestion = $parts[1];
    // Get all the blocks which starts with the suggested machine name.
    $query = $this->entityTypeManager()->getStorage('neo_component')->getQuery();
    $query->condition('id', $suggestion, 'CONTAINS');
    $item_ids = $query->accessCheck(FALSE)->execute();

    $item_ids = array_map(function ($item_id) {
      $parts = explode('.', $item_id);
      return end($parts);
    }, $item_ids);

    // Iterate through potential IDs until we get a new one. E.g.
    // 'plugin', 'plugin_2', 'plugin_3', etc.
    $count = 1;
    $machine_default = $suggestion;
    while (in_array($machine_default, $item_ids)) {
      $machine_default = $suggestion . '_' . ++$count;
    }
    return $machine_default;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $provider = explode(':', $this->getComponentId())[0];

    if ($this->moduleHandler()->moduleExists($provider)) {
      $this->addDependency('module', $provider);
    }
    elseif ($this->themeHandler()->themeExists($provider)) {
      $this->addDependency('theme', $provider);
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    return array_diff(parent::__sleep(), [
      'componentPlugin',
      'propShapes',
      'propShapeContexts',
      'slots',
      'filters',
      'access',
    ]);
  }

}
