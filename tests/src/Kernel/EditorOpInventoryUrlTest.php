<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Routing\EditorOpInventory;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Every op in the inventory resolves to a URL, in every host scope.
 *
 * The op inventory declares, per op, the route rel its URL targets. This proves
 * that reference is sound: every op's rel is a route the route family actually
 * registers, in each of the three host scopes (entity, field-UI, block), so an
 * op can never name a route no scope serves — the class of drift that lost the
 * block scope its purge route, one level up. It also resolves each op to a real
 * URL through the entity-scope generators, reading the rel, position and
 * direction off the record rather than re-parsing the op identifier.
 *
 * The op inventory itself — the vocabulary each op declares — is pinned by the
 * unit test; this is the half that needs the route family and a real field.
 *
 * @see \Drupal\neo_alchemist\Routing\EditorOpInventory
 * @see \Drupal\Tests\neo_alchemist\Unit\EditorOpInventoryTest
 */
#[Group('neo_alchemist')]
class EditorOpInventoryUrlTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The alchemist link templates are added by neo_alchemist_entity_type_alter
    // once a component-tree field exists; clear so the alter re-runs with the
    // fixture field present and the entity scope can generate URLs.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The inventory under test.
   */
  private function inventory(): EditorOpInventory {
    return $this->container->get('neo_alchemist.editor_op_inventory');
  }

  /**
   * The route family the inventory's rels reference.
   */
  private function family(): EditorRouteFamily {
    return $this->container->get('neo_alchemist.editor_route_family');
  }

  /**
   * Every op resolves to a URL, in every host scope.
   *
   * For each host scope, builds the scope's routes and, for every op, looks up
   * the route its declared rel names and generates a URL string from it. An op
   * that named a rel no scope registers fails here (the route is absent), which
   * is what makes the inventory's reference to the route family a checked one
   * rather than a hopeful string match. Generating the URL through an in-memory
   * generator over the built collection covers all three scopes uniformly —
   * without a router rebuild, the field-UI base route or the null-storage block
   * host — so "resolves to a URL in every host scope" is asserted for the
   * whole eight-op set, not just the entity scope the generators cover below.
   */
  public function testEveryOpResolvesToUrlInEveryScope(): void {
    $scopes = [
      EditorRouteFamily::SCOPE_ENTITY => [
        'entity_test',
        '/entity_test/{entity_test}/alchemist',
      ],
      EditorRouteFamily::SCOPE_FIELD_UI => [
        'entity_test',
        '/admin/structure/entity_test/alchemist',
      ],
      EditorRouteFamily::SCOPE_BLOCK => [
        'neo_alchemist_block_host',
        '/admin/config/neo/alchemist/blocks/{neo_alchemist_block}/components',
      ],
    ];

    foreach ($scopes as $scope => [$entityTypeId, $prefix]) {
      $routes = $this->family()->build($scope, $entityTypeId, $prefix);
      $collection = new RouteCollection();
      foreach ($routes as $name => $route) {
        $collection->add($name, $route);
      }
      $generator = new UrlGenerator($collection, new RequestContext());

      foreach ($this->inventory()->ops() as $op) {
        $routeName = $this->family()->routeName($scope, $entityTypeId, $op->rel);
        $this->assertArrayHasKey(
          $routeName,
          $routes,
          sprintf('Op "%s" targets rel "%s", which the %s scope registers.', $op->id, $op->rel, $scope),
        );

        // Supply a value for each of the route's path variables and generate a
        // real URL string. `direction` gets the op's declared direction so a
        // move op's URL carries up/down; the rest get a placeholder.
        $parameters = [];
        foreach ($routes[$routeName]->compile()->getPathVariables() as $variable) {
          $parameters[$variable] = $variable === 'direction' ? ($op->direction ?? 'up') : 'x-' . $variable;
        }
        $url = $generator->generate($routeName, $parameters);

        $this->assertStringStartsWith('/', $url, sprintf('Op "%s" resolves to a URL in the %s scope.', $op->id, $scope));
        $this->assertStringContainsString('/' . $op->rel, $url, sprintf('The %s URL for op "%s" targets its rel path.', $scope, $op->id));
      }
    }
  }

  /**
   * Each op resolves to its rel-route through the entity-scope generators.
   *
   * The URL is generated by the server, reading the rel, position and direction
   * from the op record — never by splitting the identifier. Add ops resolve on
   * the field item (the sibling rides as the library query keyed by position);
   * move ops carry their direction as a route parameter; the verb ops name
   * their own route. Every one lands on the route name the route family spells
   * for that rel, so the vocabulary and the routes cannot disagree.
   */
  public function testEntityScopeResolvesEveryOpToItsRelRoute(): void {
    $instance = $this->entityInstance();
    foreach ($this->inventory()->ops() as $op) {
      $expectedRoute = $this->family()->routeName(EditorRouteFamily::SCOPE_ENTITY, 'entity_test', $op->rel);

      if ($op->rel === 'library') {
        // The two add ops: a field-level rel with the sibling as a query keyed
        // by the op's declared position.
        $url = $instance->getFieldItem()->toUrl('library', [
          'query' => [$op->position => static::SEED_UUID],
        ]);
        $this->assertSame([$op->position => static::SEED_UUID], $url->getOptions()['query'], "$op->id query");
      }
      elseif ($op->rel === 'move') {
        // The two move ops: the declared direction as a route parameter.
        $url = $instance->toUrl('move', ['direction' => $op->direction]);
        $this->assertSame($op->direction, $url->getRouteParameters()['direction'], "$op->id direction");
      }
      else {
        $url = $instance->toUrl($op->rel);
      }

      $this->assertSame($expectedRoute, $url->getRouteName(), sprintf('Op "%s" resolves to its rel-route.', $op->id));
    }
  }

  /**
   * The entity-scope component instance for the fixture's seed leaf.
   */
  private function entityInstance(): ComponentEntity {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    $instance = $item->getComponent(static::SEED_UUID);
    $this->assertInstanceOf(ComponentEntity::class, $instance);
    return $instance;
  }

}
