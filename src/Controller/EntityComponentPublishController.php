<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentPublishController extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    return $this->entityFormBuilder()->getForm($entity, 'alchemist_publish', [
      'fieldItem' => $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field),
    ]);
  }

}
