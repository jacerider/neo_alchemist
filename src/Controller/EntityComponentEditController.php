<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentEditController extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field, string $uuid) {
    $instance = $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field)->getComponent($uuid);
    return $this->entityFormBuilder()->getForm($instance->getTargetEntity(), 'alchemist_edit', [
      'neo_component_instance' => $instance,
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field, string $uuid) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    $instance = $this->getComponentFieldItem($entity, $field)->getComponent($uuid);
    return $this->t('Edit %component from %label: %field_label', [
      '%component' => $instance->label(),
      '%label' => $entity->label(),
      '%field_label' => $instance->getFieldItem()->getFieldDefinition()->getLabel(),
    ]);
  }

}
