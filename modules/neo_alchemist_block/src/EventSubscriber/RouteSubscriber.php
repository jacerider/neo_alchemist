<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers the Alchemist block editor family from the shared builder.
 *
 * The block host is internal, null-storage, and carries neither an `alchemist`
 * link template nor a field_ui_base_route, so the neo_alchemist RouteSubscriber
 * skips it. This subscriber registers the same family for it, off a static
 * admin path prefix, from EditorRouteFamily::SCOPE_BLOCK. The op set the block
 * scope offers — and its deliberate purge opt-out — live in that table, not
 * here, so the block scope cannot drift from the others. This replaces the
 * mirrored routing YAML whose top comment used to state the naming invariant in
 * prose; the invariant is now enforced by the code that produces both sides of
 * it.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
 * @see \Drupal\neo_alchemist\EventSubscriber\RouteSubscriber
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs a RouteSubscriber instance.
   */
  public function __construct(
    private EditorRouteFamily $routeFamily,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $host = AlchemistBlockFieldConfig::HOST_ENTITY_TYPE_ID;
    $routes = $this->routeFamily->build(
      EditorRouteFamily::SCOPE_BLOCK,
      $host,
      '/admin/config/neo/alchemist/blocks/{neo_alchemist_block}/components',
      ['neo_alchemist_block' => ['type' => 'entity:neo_alchemist_block']],
      [],
      ['entity_type_id' => $host],
    );
    foreach ($routes as $name => $route) {
      $collection->add($name, $route);
    }
  }

}
