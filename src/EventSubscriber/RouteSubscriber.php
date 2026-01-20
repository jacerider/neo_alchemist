<?php

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
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
    private EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $fields = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');

    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if ($entityType->hasLinkTemplate('alchemist')) {
        $baseRoute = NULL;
        if ($collection->get("entity.$entityTypeId.canonical")) {
          $baseRoute = $collection->get("entity.{$entityTypeId}.canonical");
        }
        elseif ($collection->get("entity.$entityTypeId.edit-form")) {
          $baseRoute = $collection->get("entity.{$entityTypeId}.edit-form");
        }
        if ($collection->get("entity.{$entityTypeId}.latest_version")) {
          $versionRoute = $collection->get("entity.{$entityTypeId}.latest_version");
          $versionRoute->setOption('_neo_alchemist', $entityTypeId);
        }
        if ($collection->get("entity.{$entityTypeId}.revision")) {
          $revisionRoute = $collection->get("entity.{$entityTypeId}.revision");
          $revisionRoute->setOption('_neo_alchemist', $entityTypeId);
        }
        if ($baseRoute) {
          $baseRoute->setOption('_neo_alchemist', $entityTypeId);
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
            ->setRequirement('_neo_entity_component', "{$entityTypeId}.update");
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
              ->setRequirement('_neo_component_field', "neo_field.update");
            $collection->add("entity.{$entityTypeId}.alchemist.manage", $route);

            // Preview route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/preview");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentPreviewController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentPreviewController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_neo_alchemist_preview', TRUE)
              ->setOption('_admin_route', FALSE)
              ->setRequirement('_neo_component_field', "neo_field.update");
            $collection->add("entity.{$entityTypeId}.alchemist.preview", $route);

            // Library route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/library");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component_field', "neo_field.create");
            $collection->add("entity.{$entityTypeId}.alchemist.library", $route);

            // Publish route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/publish");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentPublishController',
                'title' => 'Save',
              ] + $defaults)
              ->setOption('parameters', $fieldParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component_field', "neo_field.publish");
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
              ->setRequirement('_neo_component_field', "neo_field.revert");
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
              ->setRequirement('_neo_component_field', "neo_field.reset");
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
              ->setRequirement('_neo_component_field', "neo_field.sort");
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
              ->setRequirement('_neo_component', "neo_component.create");
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
              ->setRequirement('_neo_component', "neo_component.update");
            $collection->add("entity.{$entityTypeId}.alchemist.edit", $route);

            // Component clone route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/clone/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentCloneController',
                'title' => 'Clone',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component', "neo_component.clone");
            $collection->add("entity.{$entityTypeId}.alchemist.clone", $route);

            // Component delete route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/delete/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentDeleteController',
                'title' => 'Delete',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component', "neo_component.delete");
            $collection->add("entity.{$entityTypeId}.alchemist.delete", $route);

            // Component move route.
            $route = new Route($entityType->getLinkTemplate('alchemist') . "/{neo_field}/move/{neo_component}/{direction}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentMoveController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentMoveController::getTitle',
              ] + $defaults)
              ->setOption('parameters', $fieldComponentParameters)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component_field', "neo_field.sort");
            $collection->add("entity.{$entityTypeId}.alchemist.move", $route);
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
          ];
          // If the entity type has no bundles and it doesn't use {bundle} in its
          // admin path, use the entity type.
          if (!str_contains($path, '{bundle}')) {
            $defaults['bundle'] = !$entityType->hasKey('bundle') ? $entityTypeId : '';
          }
          $entityBundleType = $bundleEntityType ?: 'bundle';

          $route = new Route("$path/alchemist");
          $route
            ->setDefaults([
              '_controller' => 'Drupal\neo_alchemist\Controller\FieldComponentController',
              '_title_callback' => 'Drupal\neo_alchemist\Controller\FieldComponentController::getTitle',
            ] + $defaults)
            ->setOptions($options)
            ->setRequirement('_neo_field_component', "entity_type_id.{$entityBundleType}.update");
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
              ->setRequirement('_neo_component_field', "neo_field.update");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.manage", $route);

            $route = new Route("$path/alchemist/{neo_field}/preview");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentPreviewController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentPreviewController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setOption('_neo_alchemist_preview', TRUE)
              ->setOption('_admin_route', FALSE)
              ->setRequirement('_neo_component_field', "neo_field.update");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.preview", $route);

            $route = new Route("$path/alchemist/{neo_field}/library");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentLibraryController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setRequirement('_neo_component_field', "neo_field.create");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.library", $route);

            $route = new Route("$path/alchemist/{neo_field}/sort");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentSortController::getTitle',
              ] + $defaults)
              ->setOptions($fieldOptions)
              ->setOption('_admin_route', TRUE)
              ->setRequirement('_neo_component_field', "neo_field.sort");
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
              ->setRequirement('_neo_component', "neo_component.create");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.add", $route);

            $route = new Route("$path/alchemist/{neo_field}/edit/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController',
                '_title_callback' => 'Drupal\neo_alchemist\Controller\InstanceComponentEditController::getTitle',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_component', "neo_component.update");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.edit", $route);

            $route = new Route("$path/alchemist/{neo_field}/clone/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentCloneController',
                'title' => 'Clone',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_component', "neo_component.clone");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.clone", $route);

            $route = new Route("$path/alchemist/{neo_field}/delete/{neo_component}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentDeleteController',
                'title' => 'Delete',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_component', "neo_component.delete");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.delete", $route);

            $route = new Route("$path/alchemist/{neo_field}/move/{neo_component}/{direction}");
            $route
              ->setDefaults([
                '_controller' => 'Drupal\neo_alchemist\Controller\InstanceComponentMoveController',
                'title' => 'Move',
              ] + $defaults)
              ->setOptions($fieldComponentOptions)
              ->setRequirement('_neo_component_field', "neo_field.sort");
            $collection->add("entity.{$entityTypeId}.field_ui.alchemist.move", $route);
          }
        }
      }
    }
  }

}
