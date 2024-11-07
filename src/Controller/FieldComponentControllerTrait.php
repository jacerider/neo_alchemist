<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;

/**
 * A trait for accessing field definitions.
 */
trait FieldComponentControllerTrait {

  /**
   * Retrieves field definitions from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return \Drupal\neo_alchemist\ComponentFieldConfigInterface[]
   *   The field definitions as determined from the passed-in route match.
   */
  protected function getFieldDefinitionsFromRouteMatch(RouteMatchInterface $routeMatch) {
    $parameters = $routeMatch->getParameters();
    $entityTypeId = $parameters->get('entity_type_id');
    $bundle = $parameters->get('bundle') ?? $entityTypeId;
    return array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
  }

  /**
   * Retrieves field definition from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return \Drupal\neo_alchemist\ComponentFieldConfigInterface
   *   The field definition as determined from the passed-in route match.
   */
  protected function getFieldDefinitionFromRouteMatch(RouteMatchInterface $routeMatch): ComponentFieldConfigInterface {
    $field = $routeMatch->getParameter('field');
    assert($field);
    $fieldDefinition = array_filter($this->getFieldDefinitionsFromRouteMatch($routeMatch), fn($fieldDefinition) => $fieldDefinition->getName() === $field);
    assert(!empty($fieldDefinition));
    return reset($fieldDefinition);
  }

}
