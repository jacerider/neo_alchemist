<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Filter;

/**
 * Interface for neo_component_filter plugins.
 */
interface ComponentFilterPluginEntityInterface extends ComponentFilterPluginInterface {

  /**
   * Gets the entity type ID this filter operates on.
   *
   * @return string
   *   The entity type ID.
   */
  public function getEntityTypeId(): string;

  /**
   * Gets the entity bundle this filter operates on.
   *
   * @return string[]
   *   The entity bundles.
   */
  public function getEntityBundles(): array;

  /**
   * Gets the entities that match the filter criteria.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entities that match the filter criteria.
   */
  public function getEntities(): array;

}
