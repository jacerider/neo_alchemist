<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ChildrenMatchMapper;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapeContextInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Filter\ComponentFilterInterface;
use Drupal\neo_alchemist\Filter\ComponentFilterPluginEntityInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityFilterValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityLoadValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityQueryValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use Drupal\neo_alchemist_taxonomy\Plugin\ComponentValue\TaxonomyChildrenValue;
use Drupal\neo_alchemist_taxonomy\Plugin\ComponentValue\TaxonomySiblingsValue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests each producer's own half of the children-match contract.
 *
 * ChildrenMatchMapperTest covers the mapping. This covers the other side: what
 * each producer resolves and, more importantly, WHICH of the three outcomes it
 * reports. That choice is the whole of a source's render-time contract and the
 * easiest thing to get wrong — unavailable() leaves the threaded value
 * standing, of([]) builds the per-child empty map that hides an unbound child,
 * and emptyValue() forces an empty value so a stop_when_found producer cannot
 * claim a map that only looks non-empty.
 *
 * Five of the seven producers had no mapping coverage at all before the seam
 * was inverted, because reaching any of this meant executing a view or running
 * a real entity query first.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchResult
 * @see \Drupal\neo_alchemist\ChildrenMatchSourceInterface::getChildrenMatchEntities()
 */
#[Group('neo_alchemist')]
class ChildrenMatchSourceResolutionTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Entity_load: nothing configured means the provider cannot act.
   */
  public function testEntityLoadWithoutConfigurationIsUnavailable(): void {
    $plugin = new EntityLoadValue(
      'entity_load',
      [],
      $this->unusedShape(),
      ['entity_type' => '', 'entity_id' => ''],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_load: a configured entity that no longer loads cannot act either.
   *
   * The distinction that matters: NOT an empty list. A deleted target must
   * leave the threaded value alone rather than blank the prop.
   */
  public function testEntityLoadWithMissingEntityIsUnavailable(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $plugin = new EntityLoadValue(
      'entity_load',
      [],
      $this->unusedShape(),
      ['entity_type' => 'node', 'entity_id' => 42],
      $this->entityTypeManager($storage),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_load: a loadable entity is the single entity to map.
   */
  public function testEntityLoadResolvesTheConfiguredEntity(): void {
    $entity = $this->contentEntity();
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $plugin = new EntityLoadValue(
      'entity_load',
      [],
      $this->cacheableShape(),
      ['entity_type' => 'node', 'entity_id' => 42],
      $this->entityTypeManager($storage),
      $this->mapper(),
    );

    $this->assertSame([$entity], $plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_filter: a filter that no longer exists cannot act.
   */
  public function testEntityFilterWithMissingFilterIsUnavailable(): void {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('getFilter')->willReturn(NULL);

    $plugin = new EntityFilterValue(
      'entity_filter',
      [],
      $this->componentShape($component),
      ['filter' => 'deleted', 'entity' => ''],
      $this->matcherReference(),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_filter: an entity filter's entities are the ones to map.
   */
  public function testEntityFilterResolvesTheFilterEntities(): void {
    $entities = [
      $this->contentEntity(),
      $this->contentEntity(),
    ];
    $filterPlugin = $this->createMock(ComponentFilterPluginEntityInterface::class);
    $filterPlugin->method('getEntities')->willReturn($entities);

    $plugin = new EntityFilterValue(
      'entity_filter',
      [],
      $this->componentShape($this->componentWithFilter($filterPlugin)),
      ['filter' => 'live', 'entity' => ''],
      $this->matcherReference(),
      $this->mapper(),
    );

    $this->assertSame($entities, $plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_reference: no configured field means the provider cannot act.
   */
  public function testEntityReferenceWithoutKeyIsUnavailable(): void {
    $component = $this->createMock(ComponentInterface::class);

    $plugin = new EntityReferenceValue(
      'entity_reference',
      [],
      $this->componentShape($component),
      ['entity' => ''],
      $this->matcherReference(),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Entity_reference: a configured field resolving to nothing forces empty.
   *
   * The third outcome, and the reason it exists. of([]) here would build the
   * per-child empty map, which isProvidedValueEmpty() reads as NON-empty — so
   * a stop_when_found entity_reference would claim it, starving the
   * entity_query fallback beneath it and force-hiding every child.
   */
  public function testEntityReferenceWithUnresolvableFieldForcesEmpty(): void {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('getTargetEntity')->willReturn($this->contentEntity());
    $component->method('getCacheableMetadata')->willReturn(new CacheableMetadata());

    $plugin = new EntityReferenceValue(
      'entity_reference',
      [],
      $this->componentShape($component),
      ['entity' => 'field_missing:target_id'],
      $this->matcherReference(),
      $this->mapper(),
    );

    $result = $plugin->getChildrenMatchEntities();
    $this->assertSame([], $result->entities, 'The source ran, so this is not unavailable().');
    $this->assertFalse($result->mapsWhenEmpty, 'Empty must resolve to [], not to the per-child map.');
  }

  /**
   * Entity_query: an unbuildable query cannot act.
   */
  public function testEntityQueryWithoutEntityTypeIsUnavailable(): void {
    $plugin = new EntityQueryValue(
      'entity_query',
      [],
      $this->unusedShape(),
      ['entity_type' => '', 'bundle' => ''],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock('Drupal\Core\Entity\EntityTypeBundleInfoInterface'),
      $this->createMock('Symfony\Component\EventDispatcher\EventDispatcherInterface'),
      $this->matcherReference(),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Taxonomy_children: a placeholder term cannot act.
   *
   * The Alchemist preview builds a new term, and falling through there is what
   * lets the Default provider's examples render instead of an empty list.
   */
  public function testTaxonomyChildrenOnNewTermIsUnavailable(): void {
    $plugin = new TaxonomyChildrenValue(
      'taxonomy_children',
      [],
      $this->termShape(TRUE, 'taxonomy_term'),
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Taxonomy_children: a component on a non-term entity cannot act.
   */
  public function testTaxonomyChildrenOnNonTermIsUnavailable(): void {
    $plugin = new TaxonomyChildrenValue(
      'taxonomy_children',
      [],
      $this->termShape(FALSE, 'node'),
      [],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Taxonomy_siblings: a placeholder term cannot act.
   */
  public function testTaxonomySiblingsOnNewTermIsUnavailable(): void {
    $plugin = new TaxonomySiblingsValue(
      'taxonomy_siblings',
      [],
      $this->termShape(TRUE, 'taxonomy_term'),
      ['exclude_self' => TRUE],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Taxonomy_siblings: a component on a non-term entity cannot act.
   */
  public function testTaxonomySiblingsOnNonTermIsUnavailable(): void {
    $plugin = new TaxonomySiblingsValue(
      'taxonomy_siblings',
      [],
      $this->termShape(FALSE, 'node'),
      ['exclude_self' => TRUE],
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mapper(),
    );

    $this->assertNull($plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * Taxonomy_children: a real term's children are the entities to map.
   */
  public function testTaxonomyChildrenResolvesChildTerms(): void {
    $children = [$this->contentEntity()];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([7]);

    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('getListCacheTags')->willReturn(['taxonomy_term_list']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('getEntityType')->willReturn($entityType);
    $storage->method('loadMultiple')->willReturn($children);

    $plugin = new TaxonomyChildrenValue(
      'taxonomy_children',
      [],
      $this->termShape(FALSE, 'taxonomy_term'),
      [],
      $this->entityTypeManager($storage),
      $this->mapper(),
    );

    $this->assertSame($children, $plugin->getChildrenMatchEntities()->entities);
  }

  /**
   * A content entity double that can be added as a cacheable dependency.
   *
   * CacheableDependencyInterface declares no return types, so an unstubbed
   * double hands NULL to CacheableMetadata and it throws.
   */
  private function contentEntity(): MockObject {
    $entity = $this->createMock(PublishableContentEntityInterface::class);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $entity;
  }

  /**
   * A real ChildrenMatchMapper over mocked collaborators.
   */
  private function mapper(): ChildrenMatchMapper {
    return new ChildrenMatchMapper(
      $this->matcherField(),
      $this->matcherReference(),
      $this->createMock('Symfony\Component\EventDispatcher\EventDispatcherInterface'),
      $this->createMock(ComponentValuePluginManagerInterface::class),
      $this->createMock('Drupal\Core\Field\FormatterPluginManager'),
      $this->createMock('Drupal\Core\Extension\ModuleHandlerInterface'),
    );
  }

  /**
   * A real MatcherField over mocked services (the class is final).
   */
  private function matcherField(): MatcherField {
    return new MatcherField(
      $this->createMock('Drupal\Core\Extension\ModuleHandlerInterface'),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock('Drupal\Core\Entity\EntityFieldManagerInterface'),
      $this->createMock('Drupal\Core\Entity\EntityTypeBundleInfoInterface'),
      $this->createMock('Drupal\Core\Routing\RouteProviderInterface'),
    );
  }

  /**
   * A real MatcherReference over mocked services (the class is final).
   */
  private function matcherReference(): MatcherReference {
    return new MatcherReference(
      $this->createMock('Drupal\Core\Extension\ModuleHandlerInterface'),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock('Drupal\Core\Entity\EntityFieldManagerInterface'),
      $this->createMock('Drupal\Core\Entity\EntityTypeBundleInfoInterface'),
    );
  }

  /**
   * An entity type manager whose every storage is the given one.
   */
  private function entityTypeManager(EntityStorageInterface $storage): MockObject {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    return $manager;
  }

  /**
   * A component whose only filter carries the given entity filter plugin.
   */
  private function componentWithFilter(ComponentFilterPluginEntityInterface $plugin): ComponentInterface&MockObject {
    $filter = $this->createMock(ComponentFilterInterface::class);
    $filter->method('getPlugin')->willReturn($plugin);
    $component = $this->createMock(ComponentInterface::class);
    $component->method('getFilter')->willReturn($filter);
    $component->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    return $component;
  }

  /**
   * A shape that answers only for its component.
   */
  private function componentShape(ComponentInterface $component): ComponentShapePluginInterface {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getComponent')->willReturn($component);
    return $this->shapeDouble([$context]);
  }

  /**
   * A shape that answers for its cacheable metadata.
   */
  private function cacheableShape(): ComponentShapePluginInterface {
    // Cacheability is a capability on the union, not one of the shape roles.
    $cache = $this->shapeRole(CacheableResponseInterface::class);
    $cache->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    return $this->shapeDouble([$cache]);
  }

  /**
   * A shape bound to a term-like entity.
   *
   * @param bool $isNew
   *   Whether the entity reports itself as new (the preview placeholder).
   * @param string $entityTypeId
   *   The entity type the component is attached to.
   */
  private function termShape(bool $isNew, string $entityTypeId): ComponentShapePluginInterface {
    $entity = $this->contentEntity();
    $entity->method('isNew')->willReturn($isNew);
    $entity->method('getEntityTypeId')->willReturn($entityTypeId);
    $entity->method('id')->willReturn('3');
    $entity->method('bundle')->willReturn('tags');

    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getEntity')->willReturn($entity);
    $cache = $this->shapeRole(CacheableResponseInterface::class);
    $cache->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    return $this->shapeDouble([$context, $cache]);
  }

}
