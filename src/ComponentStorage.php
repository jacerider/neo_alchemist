<?php

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the payment gateway storage.
 */
class ComponentStorage extends ConfigEntityStorage {

  /**
   * Loads components by the given entity.
   *
   * This method retrieves components associated with a specific entity and its
   * bundle, components for all entities of a specific type, and components for
   * all entities regardless of type.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity for which to load components.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface[]
   *   An array of loaded components, or an empty array if no components are
   *   found.
   */
  public function loadByEntity(ContentEntityInterface $entity) {
    $entityTypeId = $entity->getEntityTypeId();
    $entityBundle = $entity->bundle();

    $query = $this->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('status', 1);
    $or = $query->orConditionGroup();
    $or->condition('target_entity_type', $entityTypeId);

    // Allow components for a specific entity and bundle.
    $and = $query->andConditionGroup();
    $and->condition('target_entity_type', $entityTypeId);
    $and->condition('target_entity_bundle', $entityBundle);
    $or->condition($and);

    // Allow components for all entities.
    $or->condition('target_entity_type', '');
    $query->condition($or);

    $result = $query->execute();
    return $result ? $this->loadMultiple($result) : [];
  }

}
