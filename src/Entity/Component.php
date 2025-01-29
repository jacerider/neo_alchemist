<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Template\Attribute;
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
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *       "manage" = "Drupal\neo_alchemist\Form\ComponentManageForm",
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
  public function getComponent(): ComponentPlugin {
    /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.sdc');
    return $manager->find($this->getComponentId());
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSchema(): array {
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
    return '/' . $this->getDefaultThumbnail();
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
    $entity = NULL;
    $entityTypeManager = \Drupal::entityTypeManager();
    if ($entityTypeId = $this->getTargetEntityTypeId()) {
      if ($entity = $this->getTargetPreviewEntity()) {
        return $entity;
      }
      $entityType = $entityTypeManager->getDefinition($entityTypeId);
      $bundleKey = $entityType->getKey('bundle');
      if ($bundleKey) {
        $bundle = $this->getTargetEntityBundle();
        if (!$bundle) {
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
    }
    if (!$entity) {
      $entity = $entityTypeManager->getStorage('node')->create([
        'type' => 'page',
      ]);
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
  public function getValues(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function loadPropShapes(array $schema): array {
    /** @var \Drupal\neo_alchemist\ComponentShapePluginManager $manager */
    $manager = \Drupal::service('plugin.manager.neo_component_shape');
    // Get shapes and initialize them.
    return array_map(fn ($v) => $v->init(), $manager->getInstancesFromSchema($schema, $this, $this->getSettings()['props'] ?? [], $this->getValues()));
  }

  /**
   * {@inheritdoc}
   */
  public function getPropShapes(): array {
    if (!isset($this->propShapes)) {
      $this->propShapes = $this->loadPropShapes($this->getComponent()->metadata->schema);
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
    foreach ($this->getPropShapes() as $shapeId => $shape) {
      $value = $shape->getPropValue();
      if (is_null($value)) {
        continue;
      }
      if (!is_bool($value) && empty($value)) {
        continue;
      }
      $values[$shapeId] = $value;
      $shape->modifyAttributes($attributes);
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
  public function getSlots(): array {
    if (!isset($this->slots)) {
      $this->slots = [];
      /** @var \Drupal\neo_alchemist\ComponentSlotFactory $factory */
      $factory = \Drupal::service('neo_component.slot.factory');
      foreach ($this->getComponentSlots() as $slotName => $schema) {
        $this->slots[$slotName] = $factory->get($this, $slotName, $schema);
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
  public function preSave(EntityStorageInterface $storage) {
    $newExpression = $this->generateExpression();
    // Cyle, we need to figure out what if plugins have been enabled/disabled.
    // This means we need to  move the currentShapes/newShapes outside of just
    // the expression check. We may be able to do this just with $this->original
    // but I'm not sure yet.
    if (!isset($this->original)) {
      $this->set('schema', Json::encode($this->getComponentSchema()));
      $this->set('expression', $newExpression);
    }
    else {
      /** @var \Drupal\neo_alchemist\ComponentInterface $original */
      $original = $this->original;
      $currentSchema = Json::decode($this->get('schema'));
      $currentRootShapes = $original->loadPropShapes($currentSchema);
      $currentShapes = $original->getAllPropShapes($currentRootShapes);
      $newRootShapes = $this->getPropShapes();
      $newShapes = $this->getAllPropShapes($newRootShapes);

      // If a prop has been added/removed/type changed, we need to fire off
      // events and store the changes.
      $currentExpression = $this->getExpression();
      if ($currentExpression !== $newExpression) {
        // We add the shape ref to the key so we can find instances where the
        // prop name is the same but the shape is different.
        $currentShapesWithRef = [];
        foreach ($currentShapes as $nestedId => $shape) {
          $currentShapesWithRef[$nestedId . ':' . $shape->getRef()] = $shape;
        }
        $newShapesWithRef = [];
        foreach ($newShapes as $nestedId => $shape) {
          $newShapesWithRef[$nestedId . ':' . $shape->getRef()] = $shape;
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
      foreach ($currentRootShapes + $newRootShapes as $nestedId => $shape) {
        $currentPlugins = [];
        if (isset($currentRootShapes[$nestedId])) {
          $currentPlugins = $currentRootShapes[$nestedId]->getPlugins();
        }
        $newPlugins = [];
        if (isset($newRootShapes[$nestedId])) {
          $newPlugins = $newRootShapes[$nestedId]->getPlugins();
        }
        foreach ($currentPlugins as $nestedId => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            if (!isset($newPlugins[$nestedId][$pluginType])) {
              $currentShapes[$nestedId]->onPluginRemove($pluginType);
            }
          }
        }
        foreach ($newPlugins as $nestedId => $plugins) {
          foreach ($plugins as $pluginType => $plugin) {
            if (!isset($currentPlugins[$nestedId][$pluginType])) {
              $newShapes[$nestedId]->onPluginAdd($pluginType);
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
  public function save() {
    if ($this->isNew()) {
      $this->set('id', $this->getUniqueId());
    }
    return parent::save();
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
      'editable' => $shape->isEditable(),
      'required' => $shape->isRequired(),
    ];
    foreach ($shape->getAllShapes(TRUE) as $childShape) {
      $collection = $childShape->getValueCollection();
      foreach ($collection->getInstances() as $instanceId => $instance) {
        $instanceSettings = $instance->getConfiguration();
        if ($collection->getStatus($instanceId)) {
          if (!$childShape->allowPlugins()) {
            continue;
          }
          $nestedId = $childShape->getNestedId();
          $settings['plugins'][$nestedId][$instance->getPluginId()] = [
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
  public function getAllPropShapes(array $shapes): array {
    $allShapes = [];
    foreach ($shapes as $shape) {
      $allShapes += $shape->getAllShapes(TRUE);
    }
    ksort($allShapes);
    return $allShapes;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $build = [
      '#type' => 'component',
      '#cache' => [
        'tags' => [
          'config:neo_alchemist.component.' . $this->id(),
        ],
      ],
      '#component' => $this->getComponentId(),
      '#props' => $this->getPropValues(),
    ];
    if ($slots = $this->getSlots()) {
      $build['#slots'] = array_map(fn($slot) => $slot->toRenderable(), $slots);
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
  public function __sleep() {
    return array_diff(parent::__sleep(), ['propShapes', 'slots']);
  }

}
