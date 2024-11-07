<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldInterface;

/**
 * A component instance.
 */
final class ComponentField extends ComponentInstanceBase implements ComponentFieldInterface {

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $entityTypeId = $this->getEntity()->getEntityTypeId();
    return match($rel) {
      'edit' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.edit", ['uuid' => $this->uuid()] + $this->getFieldDefinition()->getUrlParameters()),
      'delete' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.delete", ['uuid' => $this->uuid()] + $this->getFieldDefinition()->getUrlParameters()),
      default => $this->getFieldDefinition()->toUrl($rel, $options),
    };
  }

  /**
   * {@inheritDoc}
   */
  public function setValues(array $values): self {
    parent::setValues($values);
    $this->getFieldDefinition()->setComponentValuesFromFieldItem($this->getFieldItem());
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function save() {
    return $this->getFieldDefinition()->save();
  }

  /**
   * {@inheritDoc}
   */
  public function delete() {
    $fieldItem = $this->getFieldItem();
    // Remove component field field item.
    $fieldItem->removeComponent($this->uuid());
    // Update the field definition with the new component list.
    $this->getFieldDefinition()->setComponentValuesFromFieldItem($fieldItem);
    // Save the changes to the field definition.
    return $this->getFieldDefinition()->save();
  }

}
