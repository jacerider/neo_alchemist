<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentSortController extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, RouteMatchInterface $routeMatch, string $field) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    return $this->entityFormBuilder()->getForm($entity, 'alchemist_sort', [
      'fieldItem' => $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field),
      'uuid' => $request->query->get('uuid'),
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field) {
    $fieldItem = $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field);
    return $this->t('Sort the components on %label: %field_label', [
      '%label' => $this->getEntityFromRouteMatch($routeMatch)->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]);
  }

}
