<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Presents the prop as a Drupal field item.
 *
 * A shape borrows the field system to store and edit its value: it names a
 * field type, carries storage and instance settings, and hands back a field
 * item that widgets and formatters can work with. This role is that facade.
 *
 * The setters here run before `init()`; the getters only mean anything after.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeFormInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeFieldItemInterface extends ComponentShapeIdentityInterface {

  /**
   * Sets the field type for the component shape.
   *
   * @param string $fieldType
   *   The field type to set.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setFieldType(string $fieldType): ComponentShapePluginInterface;

  /**
   * Get the field type.
   *
   * @return string
   *   The field type.
   */
  public function getFieldType(): string;

  /**
   * Sets the field storage settings.
   *
   * @param array $settings
   *   An associative array of field storage settings.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setFieldStorageSettings(array $settings): ComponentShapePluginInterface;

  /**
   * Sets the field instance settings.
   *
   * @param array $settings
   *   An associative array of field instance settings.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setFieldInstanceSettings(array $settings): ComponentShapePluginInterface;

  /**
   * Get the field item.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The field item.
   */
  public function getFieldItem(): FieldItemInterface;

  /**
   * Retrieves the field item list for the component shape.
   *
   * This method creates a new field item list instance based on the field
   * storage definition and sets the required property. It then clones the
   * current field item and sets it as the sole value of the field item list.
   * If a host entity is available, it sets the context for the field item list
   * with the host entity.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list instance.
   */
  public function getFieldItemList(): FieldItemListInterface;

  /**
   * Get the field item value.
   *
   * @return mixed
   *   The field item value.
   */
  public function getFieldItemValue(): array;

  /**
   * Set the field item value.
   *
   * @param mixed $value
   *   The field item value.
   *
   * @return $this
   */
  public function setFieldItemValue(mixed $value): ComponentShapePluginInterface;

}
