<?php

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
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
    $fieldName = $this->getName();
    return match($rel) {
      'library' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.library", $parameters),
      'add' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.add", $parameters),
      'sort' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.sort", $parameters),
      'reset' => throw new EntityMalformedException('Reset is not supported for component fields.'),
      default => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}", $parameters),
    };
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
    $this->setSetting('defaults', [
      'tree' => Json::decode($value['tree']),
      'props' => Json::decode($value['props']),
    ]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentValues(): array {
    return $this->getSetting('defaults') ?? [];
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
