<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\EntityComponentTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentAddController extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field, ComponentInterface $neo_component) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    return $this->entityFormBuilder()->getForm($entity, 'alchemist', [
      'neo_component_instance' => $this->getComponentFieldItem($entity, $field)->createComponent($neo_component),
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field, ComponentInterface $neo_component) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    $fieldItem = $this->getComponentFieldItem($entity, $field);
    return $this->t('Add %component to %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel(),
    ]);
  }

}
