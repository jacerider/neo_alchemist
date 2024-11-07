<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentManageController extends InstanceComponentManageBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;
  use IconTranslationTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $field) {
    $fieldItem = $this->getComponentFieldItem($this->getEntityFromRouteMatch($routeMatch), $field);
    return $this->build($fieldItem);
  }

  /**
   * Builds the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    if (!$entity instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException('Entity not found');
    }
    $fieldItem = $this->getComponentFieldItem($entity, $field);
    return $this->t('Layout for %label: %field_label', [
      '%label' => $entity->label(),
      '%field_label' => $fieldItem->getFieldDefinition()->getLabel()
    ]);
  }

}
