<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Plugin\Menu\LocalTask;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handle a local task for entity components.
 */
class EntityComponentLocalTask extends LocalTaskDefault {

  use StringTranslationTrait;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function getTitle(Request $request = NULL) {
    $entity = $this->getEntityFromRouteMatch();
    if ($entity instanceof ContentEntityInterface) {
      $field = $entity->getFieldDefinition($this->pluginDefinition['alchemist_field_name']);
      return $field->getLabel();
    }
    return $this->pluginDefinition['title'];
  }

  /**
   * Retrieves entity from route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch() {
    $parameter_name = $this->routeMatch()->getRouteObject()->getOption('_alchemist_entity_type_id');
    return $this->routeMatch()->getParameter($parameter_name);
  }

  /**
   * Returns the route match.
   *
   * @return \Drupal\Core\Routing\RouteMatchInterface
   *   The route match.
   */
  protected function routeMatch() {
    if (!$this->routeMatch) {
      $this->routeMatch = \Drupal::service('current_route_match');
    }
    return $this->routeMatch;
  }

}
