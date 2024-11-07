<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentLibraryController extends InstanceComponentLibraryBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field) {
    $fieldItem = $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field);
    return $this->build($fieldItem);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field) {
    $fieldItem = $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field);
    return $this->t('Select the component to add to %label: %field_label', [
      '%label' => $this->getEntityFromRouteMatch($routeMatch)->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]);
  }

}
