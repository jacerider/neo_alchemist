<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\neo_alchemist\ComponentInterface;

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
 *     "defaults",
 *     "target_entity_type",
 *     "target_entity_bundle",
 *   },
 * )
 */
final class Component extends ConfigEntityBase implements ComponentInterface {

  /**
   * The example ID.
   */
  protected string $id;

  /**
   * The example label.
   */
  protected string $label;

  /**
   * The example description.
   */
  protected string $description;

  /**
   * The SDS component.
   */
  protected string $component;

  /**
   * The defaults.
   *
   * @var array
   */
  protected ?array $defaults;

  /**
   * The target entity type.
   */
  protected ?string $target_entity_type = '';

  /**
   * The target entity bundle.
   */
  protected ?string $target_entity_bundle = '';

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
  public function getTargetEntityTypeDefinition(): EntityTypeInterface|null {
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
  public function getPropShapes(): array {
    /** @var \Drupal\neo_alchemist\ComponentShapePluginManager $manager */
    $manager = \Drupal::service('plugin.manager.neo_component_shape');
    $shapes = [];
    $component = $this->getComponent();
    $metadata = $component->metadata;
    $shapes = $manager->getInstancesFromSchema($metadata->schema, $this->defaults ?? [], $this->getTargetEntityTypeId(), $this->getTargetEntityBundle());
    return $shapes;
  }

  public function getPropValues(): array {
    $values = [];
    foreach ($this->getPropShapes() as $shapeId => $shape) {
      $value = $shape->getValue();
      if (is_null($value)) {
        continue;
      }
      if (is_array($value) && empty($value)) {
        continue;
      }
      $values[$shapeId] = $value;
    }
    return $values;
  }

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
    $suggestion = str_replace(':', '+', $this->getComponentId());

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
      $machine_default = $suggestion . '+' . ++$count;
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
