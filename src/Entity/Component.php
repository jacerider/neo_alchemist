<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

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
  protected string $expression;

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
  public function getThumbnailId(): ?string {
    return $this->thumbnail;
  }

  /**
   * Retrieves the default thumbnail path for the component.
   *
   * @return string|null
   *   The path to the default thumbnail, or NULL if not available.
   */
  public function getDefaultThumbnail(): ?string {
    return $this->getComponent()->metadata->getThumbnailPath();
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
  public function getTargetEntityTypeId(): string {
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
  public function getTargetEntityBundle(): string {
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
   * Load prop shapes.
   *
   * @param array $schema
   *   The schema.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The shapes.
   */
  protected function loadPropShapes(array $schema): array {
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
  public function getPropShape(string $propId): ?ComponentShapePluginInterface {
    return $this->getPropShapes()[$propId] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropValues(): array {
    $values = [];
    foreach ($this->getPropShapes() as $shapeId => $shape) {
      $value = $shape->getValue();
      if (is_null($value)) {
        continue;
      }
      if (!is_bool($value) && empty($value)) {
        continue;
      }
      $values[$shapeId] = $value;
    }
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
  public function getPropShapeSettings(string $propId): array {
    return $this->getAllPropShapeSettings()[$propId] ?? [];
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
    foreach ($shape->getAllChildShapes(TRUE) as $childShape) {
      foreach ($childShape->getPluginCollections() as $pluginType => $collection) {
        foreach ($collection as $plugin) {
          $pluginSettings = $plugin->getConfiguration();
          if (!empty($pluginSettings['status'])) {
            $nestedId = $childShape->getNestedId();
            if (empty($expanded) && $childShape->isNested()) {
              // If the shape is not expanded, nested settings are removed.
              continue;
            }
            if (in_array($nestedId, $expanded)) {
              // If the shape is expanded, the settings are removed.
              continue;
            }
            if ($parent = $childShape->getDirectParentShape()) {
              if (!in_array($parent->getNestedId(), $expanded)) {
                // If the parent shape is not expanded, the settings are removed.
                continue;
              }
            }
            $settings['plugins'][$nestedId][$pluginType][$plugin->getPluginId()] = [
              'id' => $plugin->getPluginId(),
              'settings' => $pluginSettings,
            ];
          }
        }
      }
    }
    $this->settings['props'][$shape->getName()] = $settings;
    return $this;
  }

  /**
   * Retrieves a flat array of all shapes keyed by their nested ID.
   *
   * This method iterates through the provided shapes and collects them into an
   * associative array. If a shape has child shapes, it collects those as well.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes
   *   An array of shape objects to process.
   * @param bool $addRefToKey
   *   (optional) Whether to add the shape's reference to the key. Defaults to
   *   FALSE.
   *
   * @return array
   *   An associative array of all shapes, with keys being the shape's nested ID
   *   (and optionally the reference) and values being the shape objects.
   */
  protected function getAllPropShapes(array $shapes, $addRefToKey = FALSE): array {
    $allShapes = [];
    foreach ($shapes as $shape) {
      $allShapes += $shape->getAllChildShapes(TRUE, $addRefToKey);
    }
    ksort($allShapes);
    return $allShapes;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    return [
      '#type' => 'component',
      '#component' => $this->getComponent()->getPluginId(),
      '#props' => $this->getPropValues(),
    ];
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
  public function save() {
    if ($this->isNew()) {
      $this->set('id', $this->getUniqueId());
    }

    $currentExpression = $this->getExpression();
    $newExpression = $this->generateExpression();
    if ($currentExpression !== $newExpression) {
      $currentSchema = Json::decode($this->get('schema'));
      $currentShapes = $this->getAllPropShapes($this->loadPropShapes($currentSchema), TRUE);
      $newRootShapes = $this->getPropShapes();
      $newShapes = $this->getAllPropShapes($newRootShapes, TRUE);

      $addedShapes = array_diff_key($newShapes, $currentShapes);
      $removedShapes = array_diff_key($currentShapes, $newShapes);
      foreach ($addedShapes as $shape) {
        $shape->onAdd();
      }
      foreach ($removedShapes as $shape) {
        $shape->onRemove();
      }

      // Only keep settings for props that are still present.
      $settings = $this->getAllPropShapeSettings();
      $settings = array_intersect_key($settings, $newRootShapes);
      $this->setSetting('props', $settings);

      // Update schema and expression.
      $this->set('schema', Json::encode($this->getComponentSchema()));
      $this->set('expression', $newExpression);
    }
    return parent::save();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
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
    return array_diff(parent::__sleep(), ['propShapes']);
  }

}
