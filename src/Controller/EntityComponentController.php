<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentController extends ControllerBase {

  use EntityComponentControllerTrait;

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    assert($entity instanceof ContentEntityInterface);
    $fieldDefinitions = array_filter($entity->getFieldDefinitions(), function ($field) {
      return $field->getType() === 'neo_component_tree';
    });

    if (count($fieldDefinitions) === 1) {
      $url = $entity->toUrl('alchemist.' . reset($fieldDefinitions)->getName());
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }

    $rows = [];
    foreach ($fieldDefinitions as $definition) {
      $row = [];
      $row['name'] = $definition->getLabel();

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $entity->toUrl('alchemist.' . $definition->getName()),
      ];
      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];
      $rows[] = $row;
    }
    $build = [
      '#title' => $this->t('Select the layout to edit'),
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

}
