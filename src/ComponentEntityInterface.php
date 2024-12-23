<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentEntityInterface extends ComponentInstanceInterface {

  /**
   * Retrieves the field component associated with this entity.
   *
   * This method fetches the field item from the field definition and checks if
   * it has a component associated with the entity's UUID. If a component is
   * found, it is returned; otherwise, NULL is returned.
   *
   * @return \Drupal\neo_alchemist\ComponentFieldInterface|null
   *   The field component associated with this entity, or NULL if no component
   *   is found.
   */
  public function getFieldComponent(): ?ComponentFieldInterface;

}
