<?php

namespace Drupal\neo_alchemist\Plugin\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;

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
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Entity\ComponentFieldConfig
   *   The field definition.
   */
  public function getFieldDefinition() {
    return $this->definition;
  }

  /**
   * {@inheritDoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    if ($this->isEmpty() && !$this->belongsToFieldConfig() && $this->getFieldDefinition()->hasComponentValues()) {
      // When the field value is empty and we are acting on an actual entity,
      // we need to populate the field with the default component values.
      ksm('hit', $this->getFieldDefinition()->getComponentValues());
      $this->appendItem($this->getFieldDefinition()->getComponentValues());
    }
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
