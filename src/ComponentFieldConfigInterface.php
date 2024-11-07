<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\field\FieldConfigInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentFieldConfigInterface extends FieldConfigInterface {

  /**
   * Retrieves URL parameters based on the target entity type and bundle.
   *
   * This method constructs an array of URL parameters that can be used to
   * generate URLs for the target entity type and bundle. It first retrieves
   * the target entity type ID and its definition. If the entity type has a
   * bundle entity type, it adds the target bundle to the parameters array.
   *
   * @return array
   *   An associative array of URL parameters where the keys are the bundle
   *   entity types and the values are the target bundles.
   */
  public function getUrlParameters(): array;

  /**
   * Retrieves the field item for the component.
   *
   * This method creates a new entity of the target entity type and bundle,
   * retrieves the field list for the specified field name, and ensures that
   * there is at least one item in the list. It then sets the component values
   * to the first item in the list and returns it.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   *   The field item for the component.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   Thrown if the entity type or storage handler cannot be retrieved.
   */
  public function getFieldItem(): ComponentTreeItem;

  /**
   * Sets the components from the given field item.
   *
   * This method sets a third-party setting for the 'neo_alchemist' component
   * using the value from the provided ComponentTreeItem.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $fieldItem
   *   The field item from which to set the component value.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setComponentValuesFromFieldItem(ComponentTreeItem $fieldItem): self;

  /**
   * Retrieves the component values for the current entity.
   *
   * This method fetches the 'component' setting from the third-party settings
   * associated with the 'neo_alchemist' module. If no component values are
   * found, it returns an empty array.
   *
   * @return array
   *   An array of component values.
   */
  public function getComponentValues(): array;

}
