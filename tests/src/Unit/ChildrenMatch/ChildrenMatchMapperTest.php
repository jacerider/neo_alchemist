<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\ChildrenMatch;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Shape\ChildShapeState;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the children-match mapping through a fake source.
 *
 * Five of the seven producers had no mapping coverage at all, because reaching
 * the mapping meant executing a view or running an entity query first. With
 * the seam inverted the entity list is the source's only render-time
 * contribution, so a fake returning a fixed list exercises the whole mapping —
 * iterability, delta versus property map, published filtering, the three empty
 * outcomes and the source's own field choices — with neither.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
 * @see \Drupal\Tests\neo_alchemist\Unit\ChildrenMatch\FakeChildrenMatchSource
 */
#[Group('neo_alchemist')]
class ChildrenMatchMapperTest extends UnitTestCase {

  /**
   * The mapper under test.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
   */
  private ChildrenMatchMapper $mapper;

  /**
   * The child shape state the mapper writes its flags onto.
   *
   * @var \Drupal\neo_alchemist\Shape\ChildShapeState
   */
  private ChildShapeState $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->state = new ChildShapeState();
    // MatcherField and MatcherReference are final, so the repo's convention is
    // a real one over mocked services. It reads `_entity:label` straight off
    // the entity, which is all these tests bind to.
    $this->mapper = new ChildrenMatchMapper(
      new MatcherField(
        $this->createMock(ModuleHandlerInterface::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(EntityFieldManagerInterface::class),
        $this->createMock(EntityTypeBundleInfoInterface::class),
        $this->createMock(RouteProviderInterface::class),
      ),
      new MatcherReference(
        $this->createMock(ModuleHandlerInterface::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(EntityFieldManagerInterface::class),
        $this->createMock(EntityTypeBundleInfoInterface::class),
      ),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(ComponentValuePluginManagerInterface::class),
      $this->createMock(FormatterPluginManager::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    $this->mapper->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * A source that cannot run leaves the threaded value exactly as it was.
   */
  public function testUnavailableSourcePassesTheThreadedValueThrough(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::unavailable());

    $existing = ['the component author placeholder'];
    $this->assertSame($existing, $this->mapper->getValues($source, $shape, [], $existing));
  }

  /**
   * An emptyValue() result resolves to [], never to the per-child map.
   *
   * The map reads as NON-empty to isProvidedValueEmpty(), so a stop_when_found
   * producer would claim it and starve the fallback below it.
   */
  public function testEmptyValueResolvesToAnEmptyArray(): void {
    $shape = $this->shape(['title', 'body'], FALSE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::emptyValue());

    $this->assertSame([], $this->mapper->getValues($source, $shape, [], ['seed']));
  }

  /**
   * An of([]) result on a non-iterable shape DOES build that map, and hides.
   *
   * The other half of the same distinction: entity_query and entity_filter
   * rely on this to hide an unbound child rather than fall back to examples.
   */
  public function testEmptyEntityListHidesEveryChild(): void {
    $shape = $this->shape(['title', 'body'], FALSE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([]));

    $this->assertSame(
      ['title' => [], 'body' => []],
      $this->mapper->getValues($source, $shape, [], NULL),
    );
    $this->assertTrue($this->state->getFlag('title', ChildShapeState::HIDDEN));
    $this->assertTrue($this->state->getFlag('body', ChildShapeState::HIDDEN));
  }

  /**
   * An iterable shape keeps integer deltas.
   */
  public function testIterableShapeReturnsDeltaKeyedList(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([
      $this->entity(TRUE, 'First'),
      $this->entity(TRUE, 'Second'),
    ]));

    $this->assertSame(
      [0 => ['title' => ['First']], 1 => ['title' => ['Second']]],
      $this->mapper->getValues($source, $shape, $this->mapping('title', '_entity:label'), NULL),
    );
  }

  /**
   * A non-iterable shape collapses to the first entity's property map.
   */
  public function testNonIterableShapeReturnsFlatPropertyMap(): void {
    $shape = $this->shape(['title'], FALSE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$this->entity(TRUE, 'Only')]));

    $this->assertSame(
      ['title' => ['Only']],
      $this->mapper->getValues($source, $shape, $this->mapping('title', '_entity:label'), NULL),
    );
  }

  /**
   * Unpublished entities are dropped when the flag is on — in one place.
   */
  public function testUnpublishedEntitiesAreDroppedWhenTheFlagIsSet(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([
      $this->entity(FALSE, 'Draft'),
      $this->entity(TRUE, 'Live'),
    ]));

    $configuration = $this->mapping('title', '_entity:label') + ['shape_published' => TRUE];
    $this->assertSame(
      [0 => ['title' => ['Live']]],
      $this->mapper->getValues($source, $shape, $configuration, NULL),
    );
  }

  /**
   * With the flag off, an unpublished entity maps like any other.
   */
  public function testUnpublishedEntitiesAreKeptWhenTheFlagIsUnset(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([
      $this->entity(FALSE, 'Draft'),
      $this->entity(TRUE, 'Live'),
    ]));

    $configuration = $this->mapping('title', '_entity:label') + ['shape_published' => FALSE];
    $this->assertSame(
      [0 => ['title' => ['Draft']], 1 => ['title' => ['Live']]],
      $this->mapper->getValues($source, $shape, $configuration, NULL),
    );
  }

  /**
   * An unmapped child is hidden rather than filled.
   */
  public function testUnmappedChildIsHidden(): void {
    $shape = $this->shape(['title', 'body'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$this->entity(TRUE, 'Mapped')]));

    $value = $this->mapper->getValues($source, $shape, $this->mapping('title', '_entity:label'), NULL);

    $this->assertSame([0 => ['title' => ['Mapped'], 'body' => []]], $value);
    $this->assertTrue($this->state->getFlag('root~body', ChildShapeState::HIDDEN));
    $this->assertNull($this->state->getFlag('root~title', ChildShapeState::HIDDEN));
  }

  /**
   * Choosing "Use Default" drops the key so the SDC example survives.
   *
   * Leaving the seeded [] would overwrite the child's own examples, and the
   * child would then "use the default" of nothing.
   */
  public function testUseDefaultRemovesTheChildKeyEntirely(): void {
    $shape = $this->shape(['title'], TRUE);
    $entity = $this->entity();
    $entity->expects($this->never())->method('label');
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$entity]));

    $value = $this->mapper->getValues($source, $shape, $this->mapping('title', '_default'), NULL);

    // The whole delta goes: it held nothing else.
    $this->assertSame([], $value);
    $this->assertTrue($this->state->getFlag('root~title', ChildShapeState::USE_DEFAULT));
  }

  /**
   * Raw literals resolve without consulting the entity at all.
   */
  public function testRawFieldsResolveWithoutTouchingTheMatcher(): void {
    $shape = $this->shape(['flag', 'label'], TRUE);
    $entity = $this->entity();
    $entity->expects($this->never())->method('label');
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$entity]));

    $configuration = [
      'shape_fields' => [
        'flag' => ['field' => '_raw:boolean_true'],
        'label' => ['field' => '_raw:string', 'string' => 'Featured'],
      ],
    ];
    $this->assertSame(
      [0 => ['flag' => TRUE, 'label' => 'Featured']],
      $this->mapper->getValues($source, $shape, $configuration, NULL),
    );
  }

  /**
   * A prefix the mapper does not own is handed to the source.
   *
   * This is the views `_view:` seam, and the structural answer to the defect
   * behind ticket 01: a source's own field choices are reached through a
   * declared interface rather than a method the mapping happens to find on
   * whatever class mixed the machinery in.
   */
  public function testUnknownPrefixIsDelegatedToTheSource(): void {
    $shape = $this->shape(['title'], TRUE);
    $entity = $this->entity();
    $entity->expects($this->never())->method('label');
    $source = new FakeChildrenMatchSource(
      ChildrenMatchResult::of([$entity]),
      ['view' => 'Rendered by the view'],
    );

    $value = $this->mapper->getValues($source, $shape, $this->mapping('title', '_view:column'), NULL);

    $this->assertSame([0 => ['title' => 'Rendered by the view']], $value);
    $this->assertSame(['view'], $source->askedFor);
  }

  /**
   * A field-source only intercepts the prefixes it declared.
   *
   * `_entity:label` is a field-matcher key, not a source pseudo field, and a
   * views mapping leans on it precisely because it resolves on a row of any
   * entity type. An earlier cut of the seam handed every unrecognised
   * underscore choice to the source, which answered NULL and silently blanked
   * every `_entity:*` binding on the one producer that has custom fields.
   */
  public function testDeclaredPrefixesDoNotSwallowFieldMatcherKeys(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(
      ChildrenMatchResult::of([$this->entity(TRUE, 'From the entity')]),
      ['view' => 'Rendered by the view'],
    );

    $value = $this->mapper->getValues($source, $shape, $this->mapping('title', '_entity:label'), NULL);

    $this->assertSame([0 => ['title' => ['From the entity']]], $value);
    $this->assertSame([], $source->askedFor, 'The source was asked about a prefix it never declared.');
  }

  /**
   * The mapping table is only offered once the source reports a scope.
   */
  public function testNoScopeMeansNoMappingTable(): void {
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::unavailable());
    $shape->expects($this->never())->method('getChildShapes');

    $form = ['#id' => 'wrapper', '#parents' => ['settings']];
    $built = $this->mapper->buildConfigurationForm($source, $shape, $form, $this->formState(), []);

    $this->assertSame('built', $built['fake_source']['#value']);
    $this->assertArrayNotHasKey('shape_fields', $built);
  }

  /**
   * A children-match shape double wired to the shared child-shape state.
   *
   * @param string[] $childNames
   *   The child shape names to fill.
   * @param bool $iterable
   *   Whether the shape takes a delta-keyed list.
   */
  private function shape(array $childNames, bool $iterable): MockObject {
    $shape = $this->createMock(ComponentShapeChildrenMatchPluginInterface::class);
    $shape->method('id')->willReturn('root');
    $shape->method('isIterable')->willReturn($iterable);
    $shape->method('getChildShapeNames')->willReturn($childNames);
    $shape->method('getChildShapeState')->willReturn($this->state);
    $shape->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    return $shape;
  }

  /**
   * A content entity double that also answers isPublished().
   *
   * @param bool $published
   *   Whether the entity reports itself as published.
   * @param string $label
   *   The label `_entity:label` resolves to.
   */
  private function entity(bool $published = TRUE, string $label = 'Entity'): MockObject {
    $entity = $this->createMock(PublishableContentEntityInterface::class);
    $entity->method('isPublished')->willReturn($published);
    $entity->method('label')->willReturn($label);
    // MatcherField has a published gate of its own, keyed off the entity
    // type. Reporting no status key keeps it out of the way so these tests
    // pin the mapper's filter rather than that one.
    $entity->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
    // CacheableDependencyInterface declares no return types, so an unstubbed
    // double hands NULL to CacheableMetadata and it throws.
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([]);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    return $entity;
  }

  /**
   * Stored settings binding one child to one field key.
   */
  private function mapping(string $child, string $field): array {
    return ['shape_fields' => [$child => ['field' => $field]]];
  }

  /**
   * A form state double.
   */
  private function formState(): MockObject {
    return $this->createMock('Drupal\Core\Form\FormStateInterface');
  }

}
