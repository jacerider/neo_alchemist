<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentFilterInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentSlotInterface;

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
 *       "prop" = "Drupal\neo_alchemist\Form\ComponentPropForm",
 *       "slot" = "Drupal\neo_alchemist\Form\ComponentSlotForm",
 *       "filter" = "Drupal\neo_alchemist\Form\ComponentFilterForm",
 *       "access" = "Drupal\neo_alchemist\Form\ComponentAccessForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *       "manage" = "Drupal\neo_alchemist\Form\ComponentManageForm",
 *       "style" = "Drupal\neo_alchemist\Form\ComponentStyleForm",
 *     },
 *   },
 *   config_prefix = "neo_component",
 *   admin_permission = "administer neo_alchemist",
 *   links = {
 *     "collection" = "/admin/config/neo/alchemist",
 *     "add-form" = "/admin/config/neo/alchemist/add/{component}",
 *     "edit-form" = "/admin/config/neo/alchemist/{neo_component}/edit",
 *     "edit-prop-form" = "/admin/config/neo/alchemist/{neo_component}/prop/{prop}",
 *     "edit-slot-form" = "/admin/config/neo/alchemist/{neo_component}/slot/{slot}",
 *     "add-filter-form" = "/admin/config/neo/alchemist/{neo_component}/filter/add",
 *     "edit-filter-form" = "/admin/config/neo/alchemist/{neo_component}/filter/{uuid}",
 *     "add-access-form" = "/admin/config/neo/alchemist/{neo_component}/access/add",
 *     "edit-access-form" = "/admin/config/neo/alchemist/{neo_component}/access/{uuid}",
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
 *     "expression",
 *     "schema",
 *     "component",
 *     "thumbnail",
 *     "settings",
 *     "target_entity_type",
 *     "target_entity_bundle",
 *   },
 * )
 */
class Component extends ConfigEntityBase implements ComponentInterface {

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
   * The rebuilding status of the component.
   *
   * Will be try when the component is rebuilding without being saved.
   *
   * @var bool
   */
  protected bool $rebuilding = FALSE;

  /**
   * The prop shapes.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   */
  protected array $propShapes;

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
  protected CacheableMetadata $cachaeableMetadata;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
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
  public function getComponentId(): string {
    return $this->component;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(): ?ComponentPlugin {
    /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.sdc');
    if (!$manager->hasDefinition($this->getComponentId())) {
      return NULL;
    }
    return $manager->find($this->getComponentId());
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSchema(): ?array {
    return $this->getComponent()->metadata->schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSlots(): array {
    return $this->getComponent()->metadata->slots ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getThumbnailId(): ?string {
    return $this->thumbnail;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultThumbnail(): ?string {
    return $this->getComponent()->metadata->getThumbnailPath();
  }

  /**
   * {@inheritdoc}
   */
  public function getThumbnail(): ?string {
    if ($thumbnailId = $this->getThumbnailId()) {
      /** @var \Drupal\neo_config_file\ConfigFileInterface $configFile */
      $configFile = $this->entityTypeManager()->getStorage('neo_config_file')->load($thumbnailId);
      if ($configFile) {
        return \Drupal::service('file_url_generator')->generateAbsoluteString($configFile->getFile()->getFileUri());
      }
    }
    if ($this->getDefaultThumbnail()) {
      return '/' . $this->getDefaultThumbnail();
    }
    return '/' . \Drupal::service('extension.list.module')->getPath('neo_alchemist') . '/images/thumbnail.jpg';
  }

  /**
   * {@inheritdoc}
   */
  public function getPath(): string {
    return $this->getComponent()->metadata->path ?? NULL;
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
    if ($entityId = \Drupal::state()->get('neo_alchemist.' . $this->id() . '.preview_entity')) {
      return \Drupal::entityTypeManager()->getStorage($this->getTargetEntityTypeId())->load($entityId);
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
  public function getValues(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    if (!isset($this->cachaeableMetadata)) {
      $this->cachaeableMetadata = new CacheableMetadata();
      $this->cachaeableMetadata->addCacheableDependency($this);
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
    return array_map(fn ($v) => $v->init(), $manager->getInstancesFromSchema($schema, $this, $this->getSetting('props', []), $this->getValues()));
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapes(): array {
    if (!isset($this->propShapes)) {
      $this->propShapes = [];
      if ($component = $this->getComponent()) {
        $this->propShapes = $this->loadPropShapes($component->metadata->schema);
      }
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
    foreach ($this->getPropShapes() as $shapeId => $shape) {
      if (!$shape->isActive()) {
        // Skip inactive shapes.
        continue;
      }
      // We call getPropValue() instead of getValue() so that shapes have
      // the opportunity to modify the value before it is returned in a way
      // that may not be compatible with the field item but is still valid
      // for SDC.
      $value = $shape->getPropValue();
      if (is_null($value)) {
        continue;
      }
      if (!is_bool($value) && empty($value)) {
        continue;
      }
      $values[$shapeId] = $value;
      $shape->modifyAttributes($attributes);
      $cacheableMetadata->addCacheableDependency($shape->getCacheableMetadata());
    }
    $values['attributes'] = $attributes;
    return $values;
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
    unset($this->propShapes);
    $expanded = $shape->getExpanded();
    $settings = [
      'prop' => $shape->getName(),
      'shape' => $shape->getPluginId(),
      'field_type' => $shape->getFieldType(),
      'expanded' => $expanded,
      'active' => $shape->isActive(),
      'editable' => $shape->isEditable(),
      'required' => $shape->isRequired(),
    ];
    foreach ($shape->getAllShapes(TRUE) as $childShape) {
      $collection = $childShape->getValueCollection();
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
  public function getFilters(): array {
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
    foreach ($this->getAccessInstances() as $accessInstance) {
      $access = $accessInstance->access($operation, $account ?? \Drupal::currentUser());
      // If any access plugin denies access, we deny access to the component
      // instance.
      if ($access->isForbidden()) {
        return $access;
      }
    }
    return AccessResult::neutral();
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
    $newExpression = $this->generateExpression();
    if (!isset($this->original)) {
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
        // Process all props and store the settings.
        $this->setSetting('props', []);
        foreach ($rootShapes as $shape) {
          $this->setPropShapeSettings($shape);
        }
        $this->setSetting('props', $this->getAllPropShapeSettings());
      }
    }
    else {
      /** @var \Drupal\neo_alchemist\ComponentInterface $original */
      $original = $this->original;
      $currentSchema = Json::decode($this->get('schema'));
      $currentRootShapes = $original->loadPropShapes($currentSchema);
      $currentShapes = $original->getPropShapesAll($currentRootShapes);
      ksort($currentShapes);
      $newRootShapes = $this->getPropShapes();
      $newShapes = $this->getPropShapesAll($newRootShapes);
      ksort($newShapes);

      // If a prop has been added/removed/type changed, we need to fire off
      // events and store the changes.
      $currentExpression = $this->getExpression();
      if ($currentExpression !== $newExpression) {
        // We add the shape ref to the key so we can find instances where the
        // prop name is the same but the shape is different.
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
            if (!isset($newPlugins[$id][$pluginType])) {
              $currentShapes[$id]->onPluginRemove($pluginType);
            }
          }
        }
        foreach ($newPlugins as $id => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            if (!isset($currentPlugins[$id][$pluginType])) {
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
  public function toRenderable() {
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
    $build = [
      '#type' => 'component',
      '#component' => $this->getComponentId(),
      '#props' => $this->getPropValues(),
    ];
    if ($slots = $this->getSlots()) {
      $build['#slots'] = array_filter(array_map(fn($slot) => $slot->toRenderable(), $slots));
    }

    $cacheableMetadata = $this->getCacheableMetadata();
    $cacheableMetadata->applyTo($build);

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
      'propShapes',
      'slots',
      'filters',
      'access',
    ]);
  }

}
