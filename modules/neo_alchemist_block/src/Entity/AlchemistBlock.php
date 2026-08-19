<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\neo_alchemist\ComponentUsage;
use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist_block\AlchemistBlockInterface;

/**
 * Defines the Alchemist block entity type.
 *
 * Each entity of this type holds an Alchemist component tree in config and is
 * exposed as a block plugin derivative in Block layout. The entity also acts
 * as the bundle of the never-persisted neo_alchemist_block_host entity type,
 * which is what allows the existing Alchemist field-config editing stack to
 * manage its components.
 *
 * @ConfigEntityType(
 *   id = "neo_alchemist_block",
 *   label = @Translation("Alchemist block"),
 *   label_collection = @Translation("Alchemist blocks"),
 *   label_singular = @Translation("alchemist block"),
 *   label_plural = @Translation("alchemist blocks"),
 *   label_count = @PluralTranslation(
 *     singular = "@count alchemist block",
 *     plural = "@count alchemist blocks",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\neo_alchemist_block\AlchemistBlockListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_alchemist_block\Form\AlchemistBlockForm",
 *       "edit" = "Drupal\neo_alchemist_block\Form\AlchemistBlockForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "block",
 *   admin_permission = "administer neo_alchemist_block",
 *   bundle_of = "neo_alchemist_block_host",
 *   links = {
 *     "collection" = "/admin/config/neo/alchemist/blocks",
 *     "add-form" = "/admin/config/neo/alchemist/blocks/add",
 *     "edit-form" = "/admin/config/neo/alchemist/blocks/{neo_alchemist_block}",
 *     "delete-form" = "/admin/config/neo/alchemist/blocks/{neo_alchemist_block}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "components",
 *   },
 * )
 */
class AlchemistBlock extends ConfigEntityBundleBase implements AlchemistBlockInterface {

  /**
   * The Alchemist block ID.
   *
   * @var string
   */
  protected string $id;

  /**
   * The Alchemist block label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The Alchemist block description.
   *
   * @var string
   */
  protected string $description = '';

  /**
   * The component tree/props values.
   *
   * @var array
   */
  protected array $components = [];

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentValues(): array {
    return $this->components;
  }

  /**
   * {@inheritdoc}
   */
  public function setComponentValues(array $values): AlchemistBlockInterface {
    $this->components = $values;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasComponentValues(): bool {
    return !empty($this->components);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    foreach ($this->getTreeComponentIds() as $componentId) {
      $this->addDependency('config', 'neo_alchemist.neo_component.' . $componentId);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Strips the removed components out of the tree and reports the block as
   * fixed. Without this the config dependency system would delete the whole
   * block — and every placement of it — because one component it contained
   * went away.
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    $componentIds = ComponentUsage::componentIdsFromDependencies($dependencies);
    if (!$componentIds) {
      return $changed;
    }
    // Config scope: the structure validator rejects an empty slot or an empty
    // subtree outright, so anything the removal empties collapses.
    $updated = ComponentTreeStructure::detachComponents($this->components, $componentIds, EmptySectionPolicy::Collapse);
    if ($updated !== $this->components) {
      $this->components = $updated;
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Collects the neo_component config entity IDs used in the tree.
   *
   * @return string[]
   *   The component config entity IDs.
   */
  protected function getTreeComponentIds(): array {
    return ComponentTreeStructure::collectComponentIds($this->components['tree'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    static::invalidateCaches();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);
    static::invalidateCaches();
  }

  /**
   * Invalidates caches that hold derived data for this entity type.
   *
   * The block deriver exposes one block plugin per entity, and the runtime
   * bundle field definition of the host entity type bakes in this entity's
   * component values.
   */
  protected static function invalidateCaches(): void {
    \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
