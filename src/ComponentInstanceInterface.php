<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentInstanceInterface extends ComponentInterface {

  /**
   * Determines if the actual component is published.
   *
   * @return bool
   *   TRUE if the component is published, FALSE otherwise.
   */
  public function isComponentPublished(): bool;

  /**
   * Retrieves the field item.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   *   The field item.
   */
  public function getFieldItem(): ComponentTreeItem;

  /**
   * Retrieves the field definition.
   *
   * @return \Drupal\neo_alchemist\ComponentFieldConfigInterface
   *   The field definition.
   */
  public function getFieldDefinition(): ComponentFieldConfigInterface;

  /**
   * Sets the instance values for the component instance.
   *
   * @param array $values
   *   An associative array of values.
   *
   * @return self
   *   The current instance of the component.
   */
  public function setValues(array $values): self;

  /**
   * Retrieves the value associated with the specified key.
   *
   * @param string|array $key
   *   The key or an array of keys to retrieve the value for.
   * @param mixed $default
   *   (optional) The default value to return if the key does not exist.
   *   Defaults to NULL.
   *
   * @return mixed
   *   The value associated with the specified key, or the default value if the
   *   key does not exist.
   */
  public function getValue($key, mixed $default = NULL): mixed;

  /**
   * Retrieves the values for the component instance.
   *
   * @return array
   *   An associative array of values.
   */
  public function getValues(): array;

}
