<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * Event that is fired when a component value is generated with an entity query.
 */
class ComponentValueEntityQueryEvent extends Event {

  const EVENT_NAME = 'neo_component_value_entity_query';

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  public ComponentShapePluginInterface $shape;

  /**
   * The entity query.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface
   */
  public QueryInterface $query;

  /**
   * Constructs the object.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape.
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query.
   */
  public function __construct(ComponentShapePluginInterface $shape, QueryInterface $query) {
    $this->shape = $shape;
    $this->query = $query;
  }

  /**
   * Gets the entity query.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The entity query.
   */
  public function getQuery() {
    return $this->query;
  }

}
