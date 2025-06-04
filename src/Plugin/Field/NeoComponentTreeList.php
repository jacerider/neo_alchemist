<?php

namespace Drupal\neo_alchemist\Plugin\Field;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\ComponentShapeQuery;
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
   * The list scope.
   *
   * @var string
   */
  protected $scope = 'entity';

  /**
   * {@inheritDoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    if (!$definition instanceof ComponentFieldConfigInterface) {
      return;
    }
    if ((!$definition->allowCustom() || !$this->belongsToFieldConfig()) && $definition->hasComponentValues()) {
      // When the field value is empty and we are acting on an actual entity,
      // we need to populate the field with the default component values.
      $this->appendItem($definition->getComponentValues());
    }
  }

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
   * Get a query object for filtering components.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\ComponentShapeQuery
   *   A query object for the components in this list.
   */
  public function getQuery() {
    return new ComponentShapeQuery($this);
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
    $definition = $this->getFieldDefinition();
    if (!$definition instanceof ComponentFieldConfigInterface) {
      return;
    }
    if (!$this->belongsToFieldConfig()) {
      $this->isDefault = FALSE;
      if (!$definition->allowCustom()) {
        // If custom is not allowed. Do not allow the field to be set. Note that
        // the defaults have already been loaded.
        return;
      }
    }
    parent::setValue($values, $notify);
  }

  /**
   * Get the scope of the field item list.
   *
   * @return string
   *   The scope of the field item list, e.g., 'entity', 'config'.
   */
  public function getScope(): string {
    return $this->scope;
  }

  /**
   * Set scope as field config.
   *
   * @return $this
   */
  public function setAsFieldConfig(): self {
    $this->scope = 'config';
    return $this;
  }

  /**
   * Checks if the item belongs to a field config.
   *
   * @return bool
   *   TRUE if the item belongs to an actual entity, FALSE otherwise.
   */
  public function belongsToFieldConfig(): bool {
    return $this->getScope() === 'config';
  }

}
