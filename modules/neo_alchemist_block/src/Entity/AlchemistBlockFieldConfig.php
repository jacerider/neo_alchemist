<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Entity;

use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist_block\AlchemistBlockInterface;

/**
 * Runtime-only field config bridging an Alchemist block to the field stack.
 *
 * Instances of this class are never persisted as field.field.* config. They
 * are built on the fly (one per Alchemist block bundle) by
 * neo_alchemist_block_entity_bundle_field_info() with an in-memory field
 * storage, and they redirect all persistence of component values into the
 * neo_alchemist_block config entity itself. Everything else — building the
 * ComponentTreeItem, URL generation, access — is inherited from
 * ComponentFieldConfig, whose route names resolve because the submodule
 * defines matching entity.neo_alchemist_block_host.field_ui.alchemist.*
 * routes.
 */
final class AlchemistBlockFieldConfig extends ComponentFieldConfig {

  public const FIELD_NAME = 'field_components';
  public const HOST_ENTITY_TYPE_ID = 'neo_alchemist_block_host';

  /**
   * Creates a runtime field config for an Alchemist block.
   *
   * @param \Drupal\neo_alchemist_block\AlchemistBlockInterface $block
   *   The Alchemist block config entity.
   *
   * @return static
   *   The runtime field config.
   */
  public static function createFromBlock(AlchemistBlockInterface $block): static {
    return new static([
      'field_storage' => static::createRuntimeStorage(),
      'field_type' => 'neo_component_tree',
      'bundle' => $block->id(),
      'label' => $block->label(),
      'settings' => [
        'allow_custom' => FALSE,
        'sizes' => [],
        'defaults' => $block->getComponentValues(),
      ],
    ], 'field_config');
  }

  /**
   * Creates the in-memory field storage for the host field.
   *
   * @return \Drupal\field\Entity\FieldStorageConfig
   *   The field storage config (never persisted).
   */
  protected static function createRuntimeStorage(): FieldStorageConfig {
    return FieldStorageConfig::create([
      'entity_type' => self::HOST_ENTITY_TYPE_ID,
      'field_name' => self::FIELD_NAME,
      'type' => 'neo_component_tree',
      'cardinality' => 1,
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * The storage is never persisted, so the parent's fallback lookup through
   * the entity field manager would throw. Recreate it instead; this also
   * self-heals instances that lost the object on a serialization round trip.
   */
  public function getFieldStorageDefinition() {
    if (!$this->fieldStorage) {
      $this->fieldStorage = static::createRuntimeStorage();
    }
    return $this->fieldStorage;
  }

  /**
   * Gets the Alchemist block this field config belongs to.
   *
   * @return \Drupal\neo_alchemist_block\AlchemistBlockInterface|null
   *   The Alchemist block config entity.
   */
  public function getBlock(): ?AlchemistBlockInterface {
    return $this->entityTypeManager()->getStorage('neo_alchemist_block')->load($this->getTargetBundle());
  }

  /**
   * {@inheritdoc}
   *
   * A runtime field config must never be persisted as field.field.* config;
   * component values are saved on the Alchemist block config entity instead.
   * setComponentValuesFromFieldItem() has already normalized the values into
   * the 'defaults' setting, and config entity storage is not statically
   * cached, so the transfer has to happen on a single loaded instance here.
   */
  public function save() {
    $block = $this->getBlock();
    if (!$block) {
      throw new \LogicException(sprintf('Alchemist block "%s" no longer exists.', $this->getTargetBundle()));
    }
    $block->setComponentValues($this->getSetting('defaults') ?: []);
    return $block->save();
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    // Runtime field configs are not persisted; there is nothing to delete.
  }

  /**
   * {@inheritdoc}
   *
   * Anything declaring a dependency on this field (e.g. neo_config_file
   * entities holding prop images) must depend on the block config entity.
   */
  public function getConfigDependencyName() {
    return $this->getBlock()?->getConfigDependencyName() ?? parent::getConfigDependencyName();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigDependencyKey() {
    return 'config';
  }

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    if ($rel === 'collection') {
      return Url::fromRoute('entity.neo_alchemist_block.collection');
    }
    return parent::toUrl($rel, $options);
  }

}
