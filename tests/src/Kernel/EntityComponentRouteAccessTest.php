<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Access\ComponentFieldAccessCheck;
use Drupal\neo_alchemist\Access\EntityComponentAccessCheck;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist_test\TestEntityComponentFieldsAlter;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * The Layout routes offer exactly what the controller can act on.
 *
 * EntityComponentController narrows an entity's tree fields with
 * neo_alchemist_entity_component_field_definitions($entity, TRUE) — locked
 * fields drop out, then hook_neo_alchemist_entity_component_fields_alter()
 * drops the ones that do not apply to this entity. Both access checks must ask
 * the same question. A raw field-definition scan says "some field on this
 * bundle is customizable" and hands out a Layout tab that lands on an empty
 * "select the layout to edit" table (the entity route), or an editor for a
 * layout that never renders on this entity (the manage route).
 *
 * The fixture models neo_alchemist_taxonomy: one hybrid field, one locked
 * field, and an alter hook that picks which one applies per entity.
 *
 * @see \Drupal\neo_alchemist\Access\EntityComponentAccessCheck
 * @see \Drupal\neo_alchemist\Access\ComponentFieldAccessCheck
 */
#[Group('neo_alchemist')]
class EntityComponentRouteAccessTest extends HybridFieldKernelTestBase {

  /**
   * A second tree field with no entity-customizable region.
   */
  private const LOCKED_FIELD = 'field_locked';

  /**
   * The locked field default layout's leaf instance uuid.
   */
  private const LOCKED_UUID = 'locked-instance-uuid';

  /**
   * An account allowed to update entity_test entities.
   */
  private AccountInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user']);

    FieldStorageConfig::create([
      'field_name' => self::LOCKED_FIELD,
      'entity_type' => 'entity_test',
      'type' => 'neo_component_tree',
    ])->save();
    FieldConfig::create([
      'field_name' => self::LOCKED_FIELD,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'settings' => [
        'allow_custom' => FALSE,
        'sizes' => [],
        'defaults' => [
          'tree' => [
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => self::LOCKED_UUID, 'component' => 'na_leaf'],
            ],
          ],
          'props' => [
            self::LOCKED_UUID => $this->leafProps('LOCKED TEXT'),
          ],
        ],
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $role->grantPermission('administer entity_test content');
    $role->save();
    $editor = User::create(['name' => 'editor', 'roles' => ['editor']]);
    $editor->save();
    $this->editor = $editor;

    TestEntityComponentFieldsAlter::reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    TestEntityComponentFieldsAlter::reset();
    parent::tearDown();
  }

  /**
   * Runs the entity Layout route's access check, returning the full result.
   */
  private function layoutRouteResult(mixed $entity): AccessResultInterface {
    $route = (new Route('/entity_test/{entity_test}/alchemist'))
      ->setRequirement('_neo_entity_component', 'entity_test.update');
    $routeMatch = new RouteMatch('entity.entity_test.alchemist', $route, ['entity_test' => $entity], ['entity_test' => $entity->id()]);
    return (new EntityComponentAccessCheck())->access($route, $routeMatch, $this->editor);
  }

  /**
   * Runs the entity Layout route's access check for an entity.
   */
  private function layoutRouteAccess(mixed $entity): bool {
    return $this->layoutRouteResult($entity)->isAllowed();
  }

  /**
   * Runs the manage route's access check, returning the full result.
   */
  private function manageRouteResult(mixed $entity, string $fieldName): AccessResultInterface {
    $item = $entity->get($fieldName)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item, sprintf('Premise: %s resolves to a tree item.', $fieldName));
    $route = (new Route('/entity_test/{entity_test}/alchemist/{neo_field}'))
      ->setRequirement('_neo_component_field', 'neo_field.update');
    $routeMatch = new RouteMatch('entity.entity_test.alchemist.manage', $route, [
      'entity_test' => $entity,
      'neo_field' => $item,
    ], ['entity_test' => $entity->id(), 'neo_field' => $fieldName]);
    return (new ComponentFieldAccessCheck())->access($route, $routeMatch, $this->editor);
  }

  /**
   * Runs the manage route's access check for one of an entity's tree fields.
   */
  private function manageRouteAccess(mixed $entity, string $fieldName): bool {
    return $this->manageRouteResult($entity, $fieldName)->isAllowed();
  }

  /**
   * With nothing narrowing the set, the hybrid field carries the Layout route.
   */
  public function testHybridFieldOpensTheLayoutRoute(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $this->assertTrue($this->layoutRouteAccess($entity), 'The Layout route is allowed while a customizable field applies.');
    $this->assertTrue($this->manageRouteAccess($entity, static::FIELD_NAME), 'The hybrid field can be managed per entity.');
    $this->assertFalse($this->manageRouteAccess($entity, self::LOCKED_FIELD), 'A locked field is never managed per entity.');
  }

  /**
   * Narrowing to a locked field closes the Layout route.
   *
   * The regression: the entity still HAS a hybrid field, so a raw scan of the
   * field definitions grants the route — and the controller, which applies the
   * alter, then finds nothing to redirect to and renders an empty picker.
   */
  public function testNarrowingToLockedFieldClosesTheLayoutRoute(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    TestEntityComponentFieldsAlter::$keep = [self::LOCKED_FIELD];

    $this->assertSame([], array_keys(neo_alchemist_entity_component_field_definitions($entity, TRUE)), 'Premise: the controller has no field to redirect to.');
    $this->assertFalse($this->layoutRouteAccess($entity), 'The Layout route is closed when no applicable field is customizable.');
  }

  /**
   * A field the entity does not apply cannot be managed on that entity.
   *
   * Without the narrowing the hybrid field stays reachable by URL, so content
   * can be authored into a layout that never renders on this entity.
   */
  public function testNarrowingHidesTheFieldFromTheManageRoute(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    TestEntityComponentFieldsAlter::$keep = [self::LOCKED_FIELD];

    $this->assertFalse($this->manageRouteAccess($entity, static::FIELD_NAME), 'The hybrid field is unreachable on an entity it does not apply to.');
    $this->assertFalse($this->manageRouteAccess($entity, self::LOCKED_FIELD), 'The applicable field is still locked, so it stays unreachable too.');
  }

  /**
   * Narrowing to the hybrid field leaves both routes open.
   */
  public function testNarrowingToTheHybridFieldKeepsBothRoutesOpen(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    TestEntityComponentFieldsAlter::$keep = [static::FIELD_NAME];

    $this->assertTrue($this->layoutRouteAccess($entity), 'The Layout route stays open for the applicable customizable field.');
    $this->assertTrue($this->manageRouteAccess($entity, static::FIELD_NAME), 'The applicable hybrid field is still manageable.');
  }

  /**
   * Editing the shared layout is about the field, never about one entity.
   *
   * The field-config scope must be immune to the per-entity narrowing —
   * otherwise a site builder loses the layout editor under Manage fields for
   * every level but the one their sample entity happens to sit at.
   */
  public function testFieldConfigScopeIgnoresTheNarrowing(): void {
    TestEntityComponentFieldsAlter::$keep = [];

    $fieldConfig = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[self::LOCKED_FIELD];
    $item = $fieldConfig->getFieldItem();
    $this->assertTrue($item->belongsToFieldConfig(), 'Premise: the item is in the field-config scope.');

    $route = (new Route('/admin/structure/entity_test/alchemist/{neo_field}'))
      ->setRequirement('_neo_component_field', 'neo_field.update');
    $routeMatch = new RouteMatch('entity.entity_test.field_ui.alchemist.manage', $route, ['neo_field' => $item], ['neo_field' => self::LOCKED_FIELD]);
    $result = (new ComponentFieldAccessCheck())->access($route, $routeMatch, $this->editor);
    $this->assertFalse($result->isForbidden(), 'The shared layout editor is not closed by the per-entity narrowing.');
    $this->assertContains(
      'config:field.field.entity_test.entity_test.' . self::LOCKED_FIELD,
      $result->getCacheTags(),
      'In the field-config scope the decision is about the field, so that is what invalidates it.'
    );
  }

  /**
   * Both checkers attach the entity their decision was made from.
   *
   * Neither used to, beyond one forbidden branch — so the results varied by
   * nothing and were invalidated by nothing. Which fields apply is
   * entity-specific (the alter hook), so both outcomes have to be
   * re-evaluated when the entity changes. Asserted on an allowed AND a denied
   * result, since it is the denial that used to be cached forever.
   */
  public function testBothCheckersAttachTheEntity(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $tag = 'entity_test:' . $entity->id();

    $this->assertContains($tag, $this->layoutRouteResult($entity)->getCacheTags());
    $this->assertContains($tag, $this->manageRouteResult($entity, static::FIELD_NAME)->getCacheTags());
    $this->assertContains($tag, $this->manageRouteResult($entity, self::LOCKED_FIELD)->getCacheTags(), 'A refusal is re-evaluated too.');

    TestEntityComponentFieldsAlter::$keep = [self::LOCKED_FIELD];
    $this->assertContains($tag, $this->layoutRouteResult($entity)->getCacheTags(), 'A closed Layout route is re-evaluated too.');
  }

  /**
   * Nothing here becomes uncacheable.
   *
   * Attaching a dependency that is not itself cacheable would drop the result
   * to max-age 0 — the failure mode the base guards against by ignoring
   * anything that is not a CacheableDependencyInterface. A tree field item is
   * exactly such a thing, which is why the field checker names its entity
   * instead.
   */
  public function testNoResultBecomesUncacheable(): void {
    $entity = $this->createTestEntity();

    $this->assertNotSame(0, $this->layoutRouteResult($entity)->getCacheMaxAge());
    $this->assertNotSame(0, $this->manageRouteResult($entity, static::FIELD_NAME)->getCacheMaxAge());
  }

}
