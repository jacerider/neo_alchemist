<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Entity\EntityComponent;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class EntityComponentController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $route_match, $field = NULL) {
    // ksm($field);
    $entity = $this->getEntityFromRouteMatch($route_match);
    assert($entity instanceof ContentEntityInterface);
    $fieldDefinitions = array_filter($entity->getFieldDefinitions(), function ($field) {
      return $field->getType() === 'neo_component_tree';
    });

    // ksm(EntityComponent::getKeyFromFieldname('field_alchemist'));
    // ksm(EntityComponent::getFieldnameFromKey('alchemist'));

    // if (count($fieldDefinitions) === 1) {
    //   return $this->toManage($entity);
    // }
    return $this->toList($entity, $fieldDefinitions);
  }

  protected function toManage(ContentEntityInterface $entity) {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Implement method: toManage'),
    ];
  }

  /**
   * Builds a list of components.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $fieldDefinitions
   *   The field definitions.
   *
   * @return array
   *   The render array.
   */
  protected function toList(ContentEntityInterface $entity, array $fieldDefinitions) {
    $rows = [];
    foreach ($fieldDefinitions as $definition) {
      $row = [];
      $row['name'] = $definition->getLabel();

      $entityComponent = EntityComponent::createFromEntity($entity, $definition->getName());

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $entityComponent->toUrl(),
      ];
      // if (isset($section)) {
      //   $links['add']['query']['section'] = $section;
      // }
      // if (isset($weight)) {
      //   $links['add']['query']['weight'] = $weight;
      // }
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
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match) {
    $parameter_name = $route_match->getRouteObject()->getOption('_alchemist_entity_type_id');
    return $route_match->getParameter($parameter_name);
  }

}
