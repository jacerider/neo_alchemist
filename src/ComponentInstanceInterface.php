<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentInstanceInterface extends ComponentInterface {

  /**
   * Checks if this instance is inherited from the field default layout.
   *
   * Only instances of a hybrid field on an actual entity can be inherited:
   * everything outside the entity-customizable regions belongs to the field
   * default layout and is not editable per entity.
   *
   * @return bool
   *   TRUE when inherited, FALSE otherwise.
   */
  public function isInherited(): bool;

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
   * Sets the parent UUID and slot for the component instance.
   *
   * @param string|null $parentUuid
   *   The parent UUID to set, or NULL to unset.
   * @param string|null $slot
   *   The slot.
   *
   * @return self
   *   The current instance of the component.
   */
  public function setParent(?string $parentUuid, ?string $slot = NULL): self;

  /**
   * Retrieves the parent UUID for the component instance.
   *
   * @return string|null
   *   The parent UUID, or NULL if not set.
   */
  public function getParentUuid(): ?string;

  /**
   * Gets the parent slot (region prop).
   *
   * @return string|null
   *   The slot.
   */
  public function getParentSlot(): ?string;

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

  /**
   * Checks data value access.
   *
   * @param string $operation
   *   The operation to be performed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   (optional) The user for which to check access, or NULL to check access
   *   for the current user. Defaults to NULL.
   * @param bool $return_as_object
   *   (optional) Defaults to FALSE.
   * @param \Drupal\neo_alchemist\Plugin\ComponentShape\ComponentShapePluginInterface|null $parentShape
   *   (optional) The parent shape plugin, if any. Defaults to NULL.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   The access result. Returns a boolean if $return_as_object is FALSE (this
   *   is the default) and otherwise an AccessResultInterface object.
   *   When a boolean is returned, the result of AccessInterface::isAllowed() is
   *   returned, i.e. TRUE means access is explicitly allowed, FALSE means
   *   access is either explicitly forbidden or "no opinion".
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE, ?ComponentShapePluginInterface $parentShape = NULL): bool|AccessResultInterface;

}
