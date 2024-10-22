<?php

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\neo_alchemist\Entity\EntityComponent;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Builds up the routes of entity alchemist.
 *
 * @see \Drupal\neo_alchemist\Plugin\neo_alchemist\display\PathPluginBase
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a RouteSubscriber instance.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $fields = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if ($entityType->hasLinkTemplate('alchemist')) {
        $baseRoute = $collection->get("entity.{$entityTypeId}.canonical");
        if ($baseRoute) {
          $route = new Route($entityType->getLinkTemplate('alchemist'));
          $parameters = $baseRoute->getOption('parameters');
          $parameters[$entityTypeId] = $parameters[$entityTypeId] ?? ['type' => 'entity:' . $entityTypeId];
          $route
            ->setDefaults([
              '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentController',
              '_title' => (string) $entityType->getLabel(),
            ])
            ->setOption('parameters', $parameters)
            ->setOption('_alchemist_entity_type_id', $entityTypeId)
            ->setRequirement('_neo_alchemist_component', "{$entityTypeId}.alchemist");
          $collection->add("entity.{$entityTypeId}.alchemist", $route);

          if (isset($fields[$entityTypeId])) {
            foreach ($fields[$entityTypeId] as $fieldName => $field) {
              $route = new Route($entityType->getLinkTemplate('alchemist') . '/' . EntityComponent::getKeyFromFieldname($fieldName));
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentController',
                  '_title' => (string) $entityType->getLabel(),
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_component', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}", $route);
            }
          }
        }
      }
    }
  }

}
