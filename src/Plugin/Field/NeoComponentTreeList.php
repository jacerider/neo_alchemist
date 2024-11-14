<?php

namespace Drupal\neo_alchemist\Plugin\Field;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Defines an item list class for map fields.
 */
class NeoComponentTreeList extends FieldItemList {

  /**
   * The data definition.
   *
   * @var \Drupal\neo_alchemist\Entity\ComponentFieldConfig
   */
  protected $definition;

  /**
   * Flag indicating if the field list uses the default component values.
   *
   * @var bool
   */
  protected $isDefault = TRUE;

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Entity\ComponentFieldConfig
   *   The field definition.
   */
  public function getFieldDefinition() {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\Plugin\DataType\EntityAdapter
   *   The parent data type.
   */
  public function getParent() {
    return $this->parent;
  }

  /**
   * {@inheritDoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    if (!$this->belongsToFieldConfig() && $this->getFieldDefinition()->hasComponentValues()) {
      // When the field value is empty and we are acting on an actual entity,
      // we need to populate the field with the default component values.
      $this->appendItem($this->getFieldDefinition()->getComponentValues());
    }
  }

  /**
   * {@inheritDoc}
   *
   * We override this method so that we can check if the actual field is empty.
   * This helps when determining if the field should be shown given that there
   * may be default values.
   */
  public function isEmpty() {
    $values = $this->getValue();
    if (!empty($values[0]['tree'])) {
      $tree = Json::decode($values[0]['tree']);
      if (empty($tree[ComponentTreeStructure::ROOT_UUID])) {
        return TRUE;
      }
    }
    return parent::isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // We do not set a default value so that the field defaults are used.
    return $this;
  }

  /**
   * Checks if the item is the default value.
   *
   * @return bool
   *   TRUE if the item is the default value, FALSE otherwise.
   */
  public function isDefault(): bool {
    return $this->isDefault;
  }

  /**
   * {@inheritDoc}
   */
  public function setValue($values, $notify = TRUE) {
    if (!$this->belongsToFieldConfig()) {
      $this->isDefault = FALSE;
      if (!$this->getFieldDefinition()->getSetting('allow_custom')) {
        // If custom is not allowed. Do not allow the field to be set. Note that
        // the defaults have already been loaded.
        return;
      }
    }
    parent::setValue($values, $notify);
  }

  /**
   * Checks if the item belongs to a field config.
   *
   * Currently, we just check if the associated content entity is new. If it is,
   * we know it was dynamically created and is therefore not attached to a real
   * entity. We therefore assume it belongs to a field config and should be
   * treated as such.
   *
   * @return bool
   *   TRUE if the item belongs to an actual entity, FALSE otherwise.
   */
  public function belongsToFieldConfig(): bool {
    return $this->getEntity()->isNew();
  }

}
