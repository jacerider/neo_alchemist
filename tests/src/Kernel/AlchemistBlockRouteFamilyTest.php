<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use Drupal\neo_alchemist_block\EventSubscriber\RouteSubscriber;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\RouteCollection;

/**
 * The block scope registers its editor family from the shared builder.
 *
 * The block submodule used to mirror the family as 160 lines of routing YAML,
 * whose top comment enforced the naming invariant in prose: the route names
 * must follow the `entity.{id}.field_ui.alchemist.*` pattern because
 * AlchemistBlockFieldConfig (a ComponentFieldConfig subclass) builds them by
 * interpolating the host entity type. This test pins the names the subscriber
 * now registers, so the deletion of that YAML is safe — a name that drifts from
 * the pattern fails here rather than when a builder clicks Layout.
 *
 * @see \Drupal\neo_alchemist_block\EventSubscriber\RouteSubscriber
 * @see \Drupal\neo_alchemist\Routing\EditorRouteFamily
 */
#[Group('neo_alchemist')]
final class AlchemistBlockRouteFamilyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block',
    'neo_settings',
    'neo_config_file',
    'neo_alchemist',
    'neo_alchemist_block',
  ];

  /**
   * The host entity type the block scope registers under.
   */
  private const HOST = 'neo_alchemist_block_host';

  /**
   * The route names the deleted routing YAML used to register, in order.
   *
   * No management landing, no purge: the block scope's deliberate op subset.
   */
  private const EXPECTED_NAMES = [
    'entity.neo_alchemist_block_host.field_ui.alchemist.manage',
    'entity.neo_alchemist_block_host.field_ui.alchemist.preview',
    'entity.neo_alchemist_block_host.field_ui.alchemist.library',
    'entity.neo_alchemist_block_host.field_ui.alchemist.sort',
    'entity.neo_alchemist_block_host.field_ui.alchemist.add',
    'entity.neo_alchemist_block_host.field_ui.alchemist.edit',
    'entity.neo_alchemist_block_host.field_ui.alchemist.clone',
    'entity.neo_alchemist_block_host.field_ui.alchemist.delete',
    'entity.neo_alchemist_block_host.field_ui.alchemist.move',
  ];

  /**
   * The block subscriber under test.
   */
  private function subscriber(): RouteSubscriber {
    return $this->container->get('neo_alchemist_block.route_subscriber');
  }

  /**
   * Drives the real subscriber over a fresh, empty collection.
   *
   * The subscriber needs no pre-existing routes: it hangs the family off a
   * static admin path prefix, so an empty collection is a fair fixture.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   The collection after the subscriber has registered its family.
   */
  private function buildCollection(): RouteCollection {
    $collection = new RouteCollection();
    $this->subscriber()->onAlterRoutes(new RouteBuildEvent($collection));
    return $collection;
  }

  /**
   * Driving the real subscriber registers exactly the deleted YAML's family.
   */
  public function testSubscriberRegistersTheBlockFamily(): void {
    $this->assertSame(self::EXPECTED_NAMES, array_keys($this->buildCollection()->all()));
  }

  /**
   * The registered routes carry the paths and gates the YAML declared.
   *
   * Spot-checks the ends of the family — the landing-adjacent `manage` and the
   * action-style `move` — plus preview's non-admin, preview-flagged options, so
   * a builder edit that silently changed a path or requirement is caught.
   */
  public function testRegisteredRoutesMatchTheYamlContract(): void {
    $collection = $this->buildCollection();

    $manage = $collection->get('entity.neo_alchemist_block_host.field_ui.alchemist.manage');
    $this->assertSame('/admin/config/neo/alchemist/blocks/{neo_alchemist_block}/components/{neo_field}', $manage->getPath());
    $this->assertSame('neo_field.update', $manage->getRequirement('_neo_component_field'));
    $this->assertSame(self::HOST, $manage->getDefault('entity_type_id'));
    $this->assertSame(['type' => 'entity:neo_alchemist_block'], $manage->getOption('parameters')['neo_alchemist_block']);
    $this->assertTrue($manage->getOption('_admin_route'));

    $preview = $collection->get('entity.neo_alchemist_block_host.field_ui.alchemist.preview');
    $this->assertFalse($preview->getOption('_admin_route'), 'Preview is the one non-admin route.');
    $this->assertTrue($preview->getOption('_neo_alchemist_preview'));

    $move = $collection->get('entity.neo_alchemist_block_host.field_ui.alchemist.move');
    $this->assertSame('/admin/config/neo/alchemist/blocks/{neo_alchemist_block}/components/{neo_field}/move/{neo_component}/{direction}', $move->getPath());
    $this->assertSame('neo_field.sort', $move->getRequirement('_neo_component_field'));
    $this->assertSame('Move', $move->getDefault('title'));
  }

  /**
   * The purge op-out is a table decision, and the subscriber honours it.
   *
   * Purge removes per-entity layout rows a locked field no longer renders. The
   * block host is null storage, so no entity ever holds such a row and purge is
   * a structural no-op here. The table declares the omission; the family never
   * carries a purge route.
   */
  public function testBlockScopeOmitsPurgeAndTheLanding(): void {
    $ops = $this->container->get('neo_alchemist.editor_route_family')->opsForScope(EditorRouteFamily::SCOPE_BLOCK);
    $this->assertNotContains('purge', $ops, 'The block scope opts out of purge.');
    $this->assertNotContains('alchemist', $ops, 'The block scope has no management landing.');

    $collection = $this->buildCollection();
    $this->assertNull($collection->get('entity.neo_alchemist_block_host.field_ui.alchemist.purge'));
  }

}
