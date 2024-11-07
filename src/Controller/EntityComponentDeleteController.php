<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentDeleteController extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field, string $uuid) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    return $this->entityFormBuilder()->getForm($entity, 'alchemist_delete', [
      'neo_component_instance' => $this->getComponentFieldItem($entity, $field)->getComponent($uuid),
    ]);
  }

}
