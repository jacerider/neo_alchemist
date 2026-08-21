<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The editor's route family is one table, byte-identical across scopes.
 *
 * The route names two URL generators build by interpolating the host entity
 * type are load-bearing: link templates, configuration and code outside this
 * repository depend on those strings. This test pins the complete registered
 * set for both host scopes, so any rename fails here rather than at a URL
 * generator that raises route-not-found only when a user clicks.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
 * @see \Drupal\neo_alchemist\EventSubscriber\RouteSubscriber
 */
#[Group('neo_alchemist')]
class EditorRouteFamilyTest extends HybridFieldKernelTestBase {

  /**
   * The route family builder under test.
   */
  private function family(): EditorRouteFamily {
    return $this->container->get('neo_alchemist.editor_route_family');
  }

  /**
   * The entity scope declares its thirteen ops, in order.
   *
   * Publish/revert/reset are here and purge is not — an entity has a per-entity
   * draft to publish but no field-wide stored data to purge.
   */
  public function testEntityScopeDeclaresItsFamily(): void {
    $names = array_keys($this->family()->build(
      EditorRouteFamily::SCOPE_ENTITY,
      'node',
      '/node/{node}/alchemist',
      ['node' => ['type' => 'entity:node']],
    ));

    $this->assertSame([
      'entity.node.alchemist',
      'entity.node.alchemist.manage',
      'entity.node.alchemist.preview',
      'entity.node.alchemist.library',
      'entity.node.alchemist.publish',
      'entity.node.alchemist.revert',
      'entity.node.alchemist.reset',
      'entity.node.alchemist.sort',
      'entity.node.alchemist.add',
      'entity.node.alchemist.edit',
      'entity.node.alchemist.clone',
      'entity.node.alchemist.delete',
      'entity.node.alchemist.move',
    ], $names);
  }

  /**
   * The field-UI scope declares its eleven ops, in order.
   *
   * Purge is here and publish/revert/reset are not — the mirror image of the
   * entity scope, and the difference that had drifted unnoticed.
   */
  public function testFieldUiScopeDeclaresItsFamily(): void {
    $names = array_keys($this->family()->build(
      EditorRouteFamily::SCOPE_FIELD_UI,
      'node',
      '/admin/structure/types/manage/{node_type}/alchemist',
      [],
      [],
      [],
      NULL,
      'node_type',
    ));

    $this->assertSame([
      'entity.node.field_ui.alchemist',
      'entity.node.field_ui.alchemist.manage',
      'entity.node.field_ui.alchemist.preview',
      'entity.node.field_ui.alchemist.library',
      'entity.node.field_ui.alchemist.sort',
      'entity.node.field_ui.alchemist.purge',
      'entity.node.field_ui.alchemist.add',
      'entity.node.field_ui.alchemist.edit',
      'entity.node.field_ui.alchemist.clone',
      'entity.node.field_ui.alchemist.delete',
      'entity.node.field_ui.alchemist.move',
    ], $names);
  }

  /**
   * Without a tree field a host offers only the management landing.
   *
   * The per-field members are gated on the host actually carrying a component
   * tree; the subscriber passes the narrowed subset for that case.
   */
  public function testFieldGatingLeavesOnlyTheLanding(): void {
    foreach ([EditorRouteFamily::SCOPE_ENTITY, EditorRouteFamily::SCOPE_FIELD_UI] as $scope) {
      $names = array_keys($this->family()->build(
        $scope,
        'node',
        '/node/{node}/alchemist',
        [],
        [],
        [],
        ['alchemist'],
        'node_type',
      ));
      $this->assertSame([$this->family()->routeName($scope, 'node', 'alchemist')], $names, "The $scope landing registers alone.");
    }
  }

  /**
   * A member op carries its path suffix, requirement and option flags.
   *
   * These are the other things a rename could quietly change: the path a URL
   * resolves to, the access check that gates it, and the two options that make
   * preview the one public, non-admin route.
   */
  public function testMemberOpCarriesPathRequirementAndOptions(): void {
    $routes = $this->family()->build(
      EditorRouteFamily::SCOPE_ENTITY,
      'node',
      '/node/{node}/alchemist',
      ['node' => ['type' => 'entity:node']],
    );

    $move = $routes['entity.node.alchemist.move'];
    $this->assertSame('/node/{node}/alchemist/{neo_field}/move/{neo_component}/{direction}', $move->getPath());
    $this->assertSame('neo_field.sort', $move->getRequirement('_neo_component_field'));
    $this->assertSame('Move', $move->getDefault('title'));
    $this->assertSame(['type' => 'neo_alchemist_field_component'], $move->getOption('parameters')['neo_component']);

    $library = $routes['entity.node.alchemist.library'];
    $this->assertSame('neo_field.create', $library->getRequirement('_neo_component_field'));

    $preview = $routes['entity.node.alchemist.preview'];
    $this->assertFalse($preview->getOption('_admin_route'), 'Preview is the one non-admin route.');
    $this->assertTrue($preview->getOption('_neo_alchemist_preview'));

    $add = $routes['entity.node.alchemist.add'];
    $this->assertSame('neo_component.create', $add->getRequirement('_neo_component'));
    $this->assertTrue($add->getOption('_admin_route'));
  }

  /**
   * The management landing is the one op whose gate differs by scope.
   *
   * The entity scope answers to the host's own update access; the field-UI
   * scope to the field-config editor's, keyed by the bundle entity type.
   */
  public function testBaseOpGateDiffersByScope(): void {
    $entity = $this->family()->build(EditorRouteFamily::SCOPE_ENTITY, 'node', '/p', [], [], [], ['alchemist']);
    $this->assertSame('node.update', $entity['entity.node.alchemist']->getRequirement('_neo_entity_component'));

    $fieldUi = $this->family()->build(EditorRouteFamily::SCOPE_FIELD_UI, 'node', '/p', [], [], [], ['alchemist'], 'node_type');
    $this->assertSame('entity_type_id.node_type.update', $fieldUi['entity.node.field_ui.alchemist']->getRequirement('_neo_field_component'));

    // With no bundle entity type the requirement falls back to the literal.
    $noBundle = $this->family()->build(EditorRouteFamily::SCOPE_FIELD_UI, 'entity_test', '/p', [], [], [], ['alchemist']);
    $this->assertSame('entity_type_id.bundle.update', $noBundle['entity.entity_test.field_ui.alchemist']->getRequirement('_neo_field_component'));
  }

  /**
   * The subscriber registers both scope families end to end.
   *
   * Driving the real subscriber over a seeded collection proves the routes the
   * builder describes are the routes that get registered — and that the two
   * scopes are the whole of it, with nothing hand-written left over.
   */
  public function testSubscriberRegistersBothScopeFamilies(): void {
    // The alchemist link template is added by the module's entity_type_alter
    // once a component-tree field exists; the base fixture created one.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();

    $collection = new RouteCollection();
    $collection->add('entity.entity_test.canonical', new Route('/entity_test/{entity_test}'));
    $collection->add('entity.entity_test.admin_form', (new Route('/admin/structure/entity_test'))->setOption('_admin_route', TRUE));

    $subscriber = $this->container->get('neo_alchemist.route_subscriber');
    $subscriber->onAlterRoutes(new RouteBuildEvent($collection));

    $expected = [];
    foreach ([EditorRouteFamily::SCOPE_ENTITY, EditorRouteFamily::SCOPE_FIELD_UI] as $scope) {
      foreach ($this->family()->opsForScope($scope) as $op) {
        $expected[] = $this->family()->routeName($scope, 'entity_test', $op);
      }
    }

    $found = array_values(array_filter(
      array_keys($collection->all()),
      static fn (string $name) => str_contains($name, '.alchemist'),
    ));
    sort($expected);
    sort($found);
    $this->assertSame($expected, $found, 'Both scope families register, and nothing beyond them.');
  }

  /**
   * The link templates and the entity-scope routes name the same ops.
   *
   * The two derive from one member table, so this is the invariant that was
   * only prose before: every link template backs a registered route (the old
   * phantom `alchemist.region` could not survive it) and every entity-scope
   * route is addressable through a template (the omitted `move` route could
   * not either).
   */
  public function testLinkTemplatesAndRoutesNameTheSameOps(): void {
    $prefix = '/node/{node}/alchemist';
    $routeNames = array_keys($this->family()->build(
      EditorRouteFamily::SCOPE_ENTITY,
      'node',
      $prefix,
      ['node' => ['type' => 'entity:node']],
    ));
    $templateKeys = array_keys($this->family()->linkTemplates(
      EditorRouteFamily::SCOPE_ENTITY,
      $prefix,
    ));

    // Every derived template has a registered route.
    foreach ($templateKeys as $key) {
      $this->assertContains("entity.node.$key", $routeNames, "Template $key backs a route.");
    }
    // Every entity-scope route is addressable through a template.
    foreach ($routeNames as $name) {
      $key = substr($name, strlen('entity.node.'));
      $this->assertContains($key, $templateKeys, "Route $name has a template.");
    }
    // The correspondence is exact — no phantom on either side.
    $this->assertCount(count($routeNames), $templateKeys);
  }

  /**
   * The two mismatches the ticket names are resolved.
   *
   * `region` had a template and a generator arm but no route; it is gone.
   * `move` had a route but no template; it gains one, pointing at the move
   * route's own path. The unchanged paths stay byte-identical.
   */
  public function testLinkTemplatesResolveRegionAndGainMove(): void {
    $prefix = '/node/{node}/alchemist';
    $templates = $this->family()->linkTemplates(EditorRouteFamily::SCOPE_ENTITY, $prefix);

    // region: the phantom whose path no route served is gone.
    $this->assertArrayNotHasKey('alchemist.region', $templates);
    // move: gains a template pointing at the move route's path.
    $this->assertSame(
      '/node/{node}/alchemist/{neo_field}/move/{neo_component}/{direction}',
      $templates['alchemist.move'],
    );
    // The base op's template is the bare alchemist prefix (and the route path
    // prefix RouteSubscriber builds the entity scope off).
    $this->assertSame($prefix, $templates['alchemist']);
    // Unchanged members keep their exact paths.
    $this->assertSame('/node/{node}/alchemist/{neo_field}', $templates['alchemist.manage']);
    $this->assertSame('/node/{node}/alchemist/{neo_field}/edit/{neo_component}', $templates['alchemist.edit']);
  }

  /**
   * The module's entity_type_alter sets exactly the derived templates.
   *
   * Drives the real alter — clearing cached definitions re-runs it — and
   * asserts the host's alchemist link templates are the table's, with `region`
   * gone and `move` present. This is what proves the alter derives from the
   * table rather than hand-writing a list beside it.
   */
  public function testEntityTypeAlterDerivesLinkTemplatesFromTable(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $entityTypeManager->clearCachedDefinitions();
    $definition = $entityTypeManager->getDefinition('entity_test');

    // The alchemist landing template is the prefix the family derives from.
    $prefix = $definition->getLinkTemplate('alchemist');
    $expected = $this->family()->linkTemplates(EditorRouteFamily::SCOPE_ENTITY, $prefix);

    $actual = array_filter(
      $definition->getLinkTemplates(),
      static fn (string $key) => $key === 'alchemist' || str_starts_with($key, 'alchemist.'),
      ARRAY_FILTER_USE_KEY,
    );

    $this->assertEquals($expected, $actual, 'The alter sets exactly the derived templates.');
    $this->assertArrayNotHasKey('alchemist.region', $actual);
    $this->assertArrayHasKey('alchemist.move', $actual);
  }

}
