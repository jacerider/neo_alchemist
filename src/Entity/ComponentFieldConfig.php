<?php

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Defines the component Field entity.
 */
class ComponentFieldConfig extends FieldConfig implements ComponentFieldConfigInterface {

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
      'publish' => throw new EntityMalformedException('Publish is not supported for component fields.'),
      'revert' => throw new EntityMalformedException('Revert is not supported for component fields.'),
      'reset' => throw new EntityMalformedException('Reset is not supported for component fields.'),
      NULL => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.manage", $parameters),
      default => parent::toUrl($rel, $options),
    };
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
  public function getFieldItem(): ComponentTreeItem {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entityType = $this->entityTypeManager()->getDefinition($this->getTargetEntityTypeId());
    $values = [];
    if ($entityType->getKey('bundle')) {
      $values[$entityType->getKey('bundle')] = $this->getTargetBundle();
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->entityTypeManager()->getStorage($this->getTargetEntityTypeId())->create($values);

    $list = $entity->get($this->getName());
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
