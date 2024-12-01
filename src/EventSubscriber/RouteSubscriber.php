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
          $defaults = [
            'entity_type_id' => $entityTypeId,
            'neo_draft' => TRUE,
          ];
          $route
            ->setDefaults([
              '_controller' => 'Drupal\neo_alchemist\Controller\EntityComponentController',
              '_title_callback' => 'Drupal\neo_alchemist\Controller\EntityComponentController::getTitle',
            ] + $defaults)
            ->setOption('parameters', $parameters)
            ->setOption('_admin_route', TRUE)
            ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.update");
          $collection->add("entity.{$entityTypeId}.alchemist", $route);

          if (isset($fields[$entityTypeId])) {
            $fieldParameters = $parameters;
            $fieldParameters['neo_field'] = [
              'type' => 'neo_alchemist_field',
            ];
            $route = new Route($entityType->getLinkTemplate('alchemist') . '/{neo_field}');
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentManageController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentManageController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.update.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.manage", $route);

            // Library route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/library");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.create.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.library", $route);

            // Publish route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/publish");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentPublishController',
                'title' => 'Publish',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.publish.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.publish", $route);

            // Revert route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/revert");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentRevertController',
                'title' => 'Revert',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.revert.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.revert", $route);

            // Reset route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/reset");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentResetController',
                'title' => 'Reset',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.reset.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.reset", $route);

            // Sort route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/sort");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.sort.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.sort", $route);

            $fieldComponentParameters = $fieldParameters;
            $fieldComponentParameters['neo_component'] = [
              'type' => 'neo_alchemist_field_component',
            ];

            // Component add route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/add/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentAddController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentAddController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.create.neo_field.neo_component");
            $collection->add("entity.{$entityTypeId}.alchemist.add", $route);

            // Component edit route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/edit/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.update.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.edit", $route);

            // Component delete route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/delete/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentDeleteController',
                'title' => 'Delete',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_entity', "{$entityTypeId}.delete.neo_field");
            $collection->add("entity.{$entityTypeId}.alchemist.delete", $route);
          }
        }

        if ($route_name = $entityType->get('field_ui_base_route')) {
          // Try to get the route from the current collection.
          if (!$entity_route = $collection->get($route_name)) {
            continue;
          }
          $path = $entity_route->getPath();

          $options = $entity_route->getOptions();
          $bundleEntityType = $entityType->getBundleEntityType();
          if ($bundleEntityType) {
            $options['parameters'][$bundleEntityType] = [
              'type' => 'entity:' . $bundleEntityType,
            ];
          }
          // Special parameter used to easily recognize all Field UI routes.
          $options['_field_ui'] = TRUE;

          $defaults = [
            'entity_type_id' => $entityTypeId,
            'entity_bundle_type' => $bundleEntityType ?: 'bundle',
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
              '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentController::getTitle',
            ] + $defaults)
            ->setOptions($options)
            ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.update');
          $collection->add("entity.{$entityTypeId}.field_ui.alchemist", $route);

          if (isset($fields[$entityTypeId])) {
            $fieldOptions = $options;
            $fieldOptions['parameters']['neo_field'] = [
              'type' => 'neo_alchemist_field',
            ];

            $route = new Route("$path/alchemist/{neo_field}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentManageController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentManageController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.update.neo_field');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.manage", $route);

            $route = new Route("$path/alchemist/{neo_field}/library");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.create.neo_field');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.library", $route);

            $route = new Route("$path/alchemist/{neo_field}/sort");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.sort.neo_field');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.sort", $route);

            $fieldComponentOptions = $fieldOptions;
            $fieldComponentOptions['parameters']['neo_component'] = [
              'type' => 'neo_alchemist_field_component',
            ];

            $route = new Route("$path/alchemist/{neo_field}/add/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentAddController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentAddController::getTitle',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.create.neo_field.neo_component');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.add", $route);

            $route = new Route("$path/alchemist/{neo_field}/edit/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController::getTitle',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.update.neo_field.neo_component');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.edit", $route);

            $route = new Route("$path/alchemist/{neo_field}/delete/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentDeleteController',
                'title' => 'Delete',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_alchemist_field', 'entity_type_id.bundle.delete.neo_field.neo_component');
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.delete", $route);
          }
        }
      }
    }
  }

}
