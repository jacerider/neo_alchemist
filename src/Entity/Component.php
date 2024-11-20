<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\neo_alchemist\ComponentInterface;
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
 *     "component",
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
   * The SDS component.
   *
   * @var string
   */
  protected string $component;

  /**
   * The settings.
   *
   * @var array|null
   */
  protected ?array $settings = [];

  /**
   * The target entity type.
   *
   * @var string|null
   */
  protected ?string $target_entity_type = '';

  /**
   * The target entity bundle.
   *
   * @var string|null
   */
  protected ?string $target_entity_bundle = '';

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
  public function getComponentSchema(): mixed {
    $component = $this->getComponent();
    // Get the component based on the ID, so we can get the schema.
    $prop_schema = $component->metadata->schema;
    // Encode & decode, so we transform an associative array to an stdClass
    // recursively.
    try {
      $schema = json_decode(
        json_encode($prop_schema, JSON_THROW_ON_ERROR),
        FALSE,
        512,
        JSON_THROW_ON_ERROR
      );
    }
    catch (\JsonException $e) {
      $schema = (object) [];
    }

    return $schema;
  }

  // public function getValueProviderIds(): array {
  //   ksm($this->providers);
  //   $ids = array_map(function ($provider) {
  //     return $provider['plugin'];
  //   }, $this->providers);
  //   return array_combine($ids, $ids);
  // }

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
  public function save() {
    if ($this->isNew()) {
      $this->set('id', $this->getUniqueId());
    }
    return parent::save();
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
  public function getTargetEntity(): ContentEntityInterface {
    $entity = NULL;
    $entityTypeId = $this->getTargetEntityTypeId();
    $entityTypeManager = \Drupal::entityTypeManager();
    if ($entityTypeId) {
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
  public function getPropShapes(): array {
    /** @var \Drupal\neo_alchemist\ComponentShapePluginManager $manager */
    $manager = \Drupal::service('plugin.manager.neo_component_shape');
    return $manager->getInstancesFromSchema($this->getComponent()->metadata->schema, $this->getTargetEntity(), $this->getValues(), $this->getSettings()['props'] ?? []);
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
    // $suggestion = str_replace(':', '__', $this->getComponentId());

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

}
