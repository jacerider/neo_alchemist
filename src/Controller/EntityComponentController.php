<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, RouteMatchInterface $routeMatch) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    assert($entity instanceof ContentEntityInterface);
    $fieldDefinitions = array_filter($entity->getFieldDefinitions(), function ($field) {
      return $field instanceof ComponentFieldConfigInterface && $field->allowCustom();
    });

    if (count($fieldDefinitions) === 1) {
      $url = $entity->toUrl('alchemist.manage')->setRouteParameter('neo_field', ComponentFieldConfig::getKeyFromFieldname(reset($fieldDefinitions)->getName()));
      $destination = $request->query->get('destination');
      $request->query->set('destination', NULL);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters(), [
        'query' => ['destination' => $destination],
      ]);
    }

    $rows = [];
    foreach ($fieldDefinitions as $definition) {
      $row = [];
      $row['name'] = $definition->getLabel();

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $entity->toUrl('alchemist.manage')->setRouteParameter('neo_field', ComponentFieldConfig::getKeyFromFieldname($definition->getName())),
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

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch) {
    $entity = $this->getEntityFromRouteMatch($routeMatch);
    assert($entity instanceof ContentEntityInterface);
    $fieldDefinitions = array_filter($entity->getFieldDefinitions(), function ($field) {
      return $field->getType() === 'neo_component_tree';
    });
    if (count($fieldDefinitions) === 1) {
      return $this->t('Layout');
    }
    return $this->t('Layouts');
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $routeMatch) {
    $parameter_name = $routeMatch->getRouteObject()->getDefault('entity_type_id');
    return $routeMatch->getParameter($parameter_name);
  }

}
