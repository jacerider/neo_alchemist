<?php

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Defines the component Field entity.
 */
class ComponentFieldConfig extends NeoFieldConfig implements ComponentFieldConfigInterface {

  /**
   * Memoized entity-customizable region anchors of the default layout.
   *
   * @var array|null
   */
  protected ?array $customRegions = NULL;

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $entityTypeId = $this->getTargetEntityTypeId();
    $parameters = $this->getUrlParameters();
    $parameters['neo_field'] = self::getKeyFromFieldname($this->getName());
    return match($rel) {
      'collection' => Url::fromRoute("entity.{$entityTypeId}.field_ui_fields", $parameters),
      'preview' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.preview", $parameters),
      'library' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.library", $parameters),
      'add' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.add", $parameters),
      'sort' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.sort", $parameters),
      // The mirror image of the three below: purging stored entity data is
      // only meaningful for the field, never for a single entity.
      'purge' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.purge", $parameters),
      'publish' => throw new EntityMalformedException('Publish is not supported for component fields.'),
      'revert' => throw new EntityMalformedException('Revert is not supported for component fields.'),
      'reset' => throw new EntityMalformedException('Reset is not supported for component fields.'),
      NULL => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.manage", $parameters),
      default => parent::toUrl($rel, $options),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginal(): ?static {
    if ($this->originalEntity) {
      // Needed so we can pass FieldConfig::preSave() validation.
      $entity_class = 'Drupal\neo_alchemist\Entity\ComponentFieldConfig';
      return new $entity_class($this->originalEntity->toArray(), 'field_config');
    }
    return $this->originalEntity;
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = AccessResult::allowedIfHasPermission($account ?? \Drupal::currentUser(), 'administer ' . $this->getTargetEntityTypeId() . ' fields');
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Check if field allows per-entity custom components.
   *
   * @return bool
   *   TRUE if custom components are allowed, FALSE otherwise.
   */
  public function allowCustom(): bool {
    return $this->getSetting('allow_custom');
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomRegions(): array {
    if (!isset($this->customRegions)) {
      $this->customRegions = [];
      if (!$this->allowCustom() && $this->hasComponentValues()) {
        // The component id of every instance placed in the default layout, in
        // tree order.
        $instances = ComponentTreeStructure::collectInstances($this->getComponentValues()['tree'] ?? []);
        // Resolve which components flag a region prop as entity-customizable.
        // The 'region_custom' value plugin is stored per shape id, and shape
        // ids double as the tree slot keys.
        $slotsByComponent = [];
        $storage = $this->entityTypeManager()->getStorage('neo_component');
        foreach ($instances as $uuid => $componentId) {
          if (!array_key_exists($componentId, $slotsByComponent)) {
            $slotsByComponent[$componentId] = [];
            $component = $storage->load($componentId);
            if ($component instanceof ComponentInterface) {
              foreach ($component->getAllPropShapeSettings() as $propSettings) {
                foreach ($propSettings['plugins'] ?? [] as $shapeId => $plugins) {
                  if (isset($plugins['region_custom'])) {
                    $slotsByComponent[$componentId][] = $shapeId;
                  }
                }
              }
            }
          }
          if ($slotsByComponent[$componentId]) {
            $this->customRegions[$uuid] = [
              'component' => $componentId,
              'slots' => $slotsByComponent[$componentId],
            ];
          }
        }
      }
    }
    return $this->customRegions;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCustomRegions(): bool {
    return !empty($this->getCustomRegions());
  }

  /**
   * {@inheritdoc}
   */
  public function isHybrid(): bool {
    return !$this->allowCustom() && $this->hasCustomRegions();
  }

  /**
   * {@inheritdoc}
   */
  public function getUrlParameters(): array {
    $entityTypeId = $this->getTargetEntityTypeId();
    $entityType = $this->entityTypeManager()->getDefinition($entityTypeId);
    $parameters = [];
    if ($bundleEntityType = $entityType->getBundleEntityType()) {
      $parameters[$bundleEntityType] = $this->getTargetBundle();
    }
    return $parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldItem(?ContentEntityInterface $entity = NULL): ComponentTreeItem {
    if (!$entity) {
      $entityType = $this->entityTypeManager()->getDefinition($this->getTargetEntityTypeId());
      $values = [];
      if ($entityType->getKey('bundle')) {
        $values[$entityType->getKey('bundle')] = $this->getTargetBundle();
      }
      else {
        // We need to make sure we load an entity that contains this field.
        $field_storage = FieldStorageConfig::loadByName($this->getTargetEntityTypeId(), $this->getName());
        $bundles = $field_storage->getBundles();
        $values[$entityType->getKey('bundle')] = reset($bundles);
      }
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entityTypeManager()->getStorage($this->getTargetEntityTypeId())->create($values);
    }

    /** @var \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList $list */
    $list = $entity->get($this->getName());
    // We clone it so that it does not affect the actual entity field.
    if (!$entity->isNew()) {
      $list = clone $list;
    }
    // Set scope as config.
    $list->setAsFieldConfig();
    if ($list->isEmpty()) {
      $list->appendItem([]);
    }
    $item = $list->first();
    assert($item instanceof ComponentTreeItem);
    $item->setValue($this->getComponentValues());
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function setSetting($setting_name, $value) {
    if ($setting_name === 'defaults') {
      // The customisable-region anchors are read out of these defaults, and
      // isHybrid() is derived from them in turn. Field-scope saves rewrite the
      // defaults on the shared, EntityFieldManager-cached definition object
      // mid-request, so a memo left in place would keep describing the layout
      // as it was before the save.
      $this->customRegions = NULL;
    }
    return parent::setSetting($setting_name, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function setComponentValuesFromFieldItem(ComponentTreeItem $fieldItem): self {
    $value = $fieldItem->getValue();
    $value['tree'] = Json::decode($value['tree']);
    $value['props'] = Json::decode($value['props']);
    if (empty($value['tree'][ComponentTreeStructure::ROOT_UUID])) {
      $this->setSetting('defaults', []);
    }
    else {
      $this->setSetting('defaults', $value);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentValues(): array {
    return $this->getSetting('defaults') ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function hasComponentValues(): bool {
    return !empty($this->getComponentValues());
  }

  /**
   * Get the key from a field name.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The key.
   */
  public static function getKeyFromFieldname(string $field_name): string {
    return str_replace('_', '-', substr($field_name, 6));
  }

  /**
   * Get the field name from a key.
   *
   * @param string $key
   *   The key.
   *
   * @return string
   *   The field name.
   */
  public static function getFieldnameFromKey(string $key): string {
    return 'field_' . str_replace('-', '_', $key);
  }

}
