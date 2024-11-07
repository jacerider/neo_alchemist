<?php

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
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
              '_title' => 'Select the layout to edit',
            ])
            ->setOption('parameters', $parameters)
            ->setOption('_admin_route', TRUE)
            ->setOption('_alchemist_entity_type_id', $entityTypeId)
            ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist");
          $collection->add("entity.{$entityTypeId}.alchemist", $route);

          if (isset($fields[$entityTypeId])) {
            foreach ($fields[$entityTypeId] as $fieldName => $field) {
              $fieldNameKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
              $route = new Route($entityType->getLinkTemplate('alchemist') . '/' . $fieldNameKey);
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentManageController',
                  '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentManageController::getTitle',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}", $route);

              // Library route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/library");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentLibraryController',
                  '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentLibraryController::getTitle',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.library", $route);

              // Reset route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/reset");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentResetController',
                  'title' => 'Reset',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.reset", $route);

              // Sort route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/sort");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentSortController',
                  '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentSortController::getTitle',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.sort", $route);

              // Component add route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/add/{neo_component}");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentAddController',
                  '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentAddController::getTitle',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.add", $route);

              // Component edit route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/edit/{uuid}");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentEditController',
                  '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentEditController::getTitle',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.edit", $route);

              // Component delete route.
              $route = new Route($entityType->getLinkTemplate('alchemist') . "/$fieldNameKey/delete/{uuid}");
              $route
                ->setDefaults([
                  '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentDeleteController',
                  'title' => 'Delete',
                  'field' => $fieldName,
                ])
                ->setOption('parameters', $parameters)
                ->setOption('_admin_route', TRUE)
                ->setOption('_alchemist_entity_type_id', $entityTypeId)
                ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.alchemist.{$fieldName}");
              $collection->add("entity.{$entityTypeId}.alchemist.{$fieldName}.delete", $route);
            }
          }
        }
      }
      if ($route_name = $entityType->get('field_ui_base_route')) {
        // Try to get the route from the current collection.
        if (!$entity_route = $collection->get($route_name)) {
          continue;
        }
        $path = $entity_route->getPath();

        $options = $entity_route->getOptions();
        if ($bundleEntityType = $entityType->getBundleEntityType()) {
          $options['parameters'][$bundleEntityType] = [
            'type' => 'entity:' . $bundleEntityType,
          ];
        }
        // Special parameter used to easily recognize all Field UI routes.
        $options['_field_ui'] = TRUE;

        $defaults = [
          'entity_type_id' => $entityTypeId,
        ];
        // If the entity type has no bundles and it doesn't use {bundle} in its
        // admin path, use the entity type.
        if (!str_contains($path, '{bundle}')) {
          $defaults['bundle'] = !$entityType->hasKey('bundle') ? $entityTypeId : '';
        }

        $route = new Route("$path/alchemist");
        $route
          ->setDefaults([
            '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentController',
            '_title' => 'Select the layout to edit',
          ] + $defaults)
          ->setOptions($options)
          ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
        $collection->add("entity.{$entityTypeId}.field_ui.alchemist", $route);

        if (isset($fields[$entityTypeId])) {
          foreach ($fields[$entityTypeId] as $fieldName => $field) {
            $fieldNameKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
            $route = new Route("$path/alchemist/$fieldNameKey");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentManageController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentManageController::getTitle',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}", $route);

            $route = new Route("$path/alchemist/$fieldNameKey/library");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentLibraryController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentLibraryController::getTitle',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.library", $route);

            $route = new Route("$path/alchemist/$fieldNameKey/sort");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentSortController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentSortController::getTitle',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.sort", $route);

            $route = new Route("$path/alchemist/$fieldNameKey/add/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentAddController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentAddController::getTitle',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.add", $route);

            $route = new Route("$path/alchemist/$fieldNameKey/edit/{uuid}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentEditController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentEditController::getTitle',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.edit", $route);

            $route = new Route("$path/alchemist/$fieldNameKey/delete/{uuid}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentDeleteController',
                'title' => 'Delete',
                'field' => $fieldName,
              ] + $defaults)
              ->setOptions($options)
              ->setRequirement('_neo_alchemist_field', $entityType->getBundleEntityType() ?? $entityTypeId);
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.delete", $route);
          }
        }
      }
    }
  }

}
