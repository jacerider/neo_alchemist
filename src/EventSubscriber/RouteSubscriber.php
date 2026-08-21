<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use Symfony\Component\Routing\RouteCollection;

/**
 * Builds up the routes of entity alchemist.
 *
 * The editor's route family is one table, owned by EditorRouteFamily. This
 * subscriber does not hand-write routes; it discovers which host entity
 * types carry a component tree, then asks the builder for each scope's family
 * off the right path prefix. The block submodule registers the same family from
 * a subscriber of its own.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a RouteSubscriber instance.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager,
    private EditorRouteFamily $routeFamily,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $fields = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');

    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if (!$entityType->hasLinkTemplate('alchemist')) {
        continue;
      }
      // A host only gains the per-field members when it actually carries a
      // component tree field; the management landing exists regardless.
      $hasFields = isset($fields[$entityTypeId]);
      $this->addEntityScopeRoutes($collection, $entityType, $entityTypeId, $hasFields);
      $this->addFieldUiScopeRoutes($collection, $entityType, $entityTypeId, $hasFields);
    }
  }

  /**
   * Registers the entity scope's family off the host's alchemist link template.
   */
  private function addEntityScopeRoutes(RouteCollection $collection, EntityTypeInterface $entityType, string $entityTypeId, bool $hasFields): void {
    $baseRoute = NULL;
    if ($collection->get("entity.$entityTypeId.canonical")) {
      $baseRoute = $collection->get("entity.{$entityTypeId}.canonical");
    }
    elseif ($collection->get("entity.$entityTypeId.edit-form")) {
      $baseRoute = $collection->get("entity.{$entityTypeId}.edit-form");
    }
    // Tag the routes the editor overlays sit on top of, so the running site can
    // recognise them; this happens whether or not the host carries a field.
    if ($versionRoute = $collection->get("entity.{$entityTypeId}.latest_version")) {
      $versionRoute->setOption('_neo_alchemist', $entityTypeId);
    }
    if ($revisionRoute = $collection->get("entity.{$entityTypeId}.revision")) {
      $revisionRoute->setOption('_neo_alchemist', $entityTypeId);
    }
    if (!$baseRoute) {
      return;
    }
    $baseRoute->setOption('_neo_alchemist', $entityTypeId);

    $parameters = $baseRoute->getOption('parameters') ?? [];
    $parameters[$entityTypeId] = $parameters[$entityTypeId] ?? ['type' => 'entity:' . $entityTypeId];
    $defaults = [
      'entity_type_id' => $entityTypeId,
      'neo_draft' => TRUE,
    ];
    $ops = $hasFields ? NULL : ['alchemist'];

    $routes = $this->routeFamily->build(
      EditorRouteFamily::SCOPE_ENTITY,
      $entityTypeId,
      $entityType->getLinkTemplate('alchemist'),
      $parameters,
      [],
      $defaults,
      $ops,
    );
    foreach ($routes as $name => $route) {
      $collection->add($name, $route);
    }
  }

  /**
   * Registers the field-UI scope's family off the field-UI base route.
   */
  private function addFieldUiScopeRoutes(RouteCollection $collection, EntityTypeInterface $entityType, string $entityTypeId, bool $hasFields): void {
    $routeName = $entityType->get('field_ui_base_route');
    if (!$routeName) {
      return;
    }
    if (!$entityRoute = $collection->get($routeName)) {
      return;
    }
    $path = $entityRoute->getPath();

    $options = $entityRoute->getOptions();
    // The builder owns the `parameters` option; carry the rest of the field-UI
    // base route's options through, and mark the family as Field UI routes.
    $parameters = $options['parameters'] ?? [];
    unset($options['parameters']);
    $bundleEntityType = $entityType->getBundleEntityType();
    if ($bundleEntityType) {
      $parameters[$bundleEntityType] = ['type' => 'entity:' . $bundleEntityType];
    }
    $options['_field_ui'] = TRUE;

    $defaults = ['entity_type_id' => $entityTypeId];
    // If the entity type has no bundles and it doesn't use {bundle} in its
    // admin path, use the entity type.
    if (!str_contains($path, '{bundle}')) {
      $defaults['bundle'] = !$entityType->hasKey('bundle') ? $entityTypeId : '';
    }
    $ops = $hasFields ? NULL : ['alchemist'];

    $routes = $this->routeFamily->build(
      EditorRouteFamily::SCOPE_FIELD_UI,
      $entityTypeId,
      "$path/alchemist",
      $parameters,
      $options,
      $defaults,
      $ops,
      $bundleEntityType,
    );
    foreach ($routes as $name => $route) {
      $collection->add($name, $route);
    }
  }

}
