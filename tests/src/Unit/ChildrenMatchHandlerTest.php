<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

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
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\ChildShapeState;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Event\ComponentValueEvent;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Exercises the pseudo-field handlers through the mapper's fake source.
 *
 * Each handler is one object owning its option, form branch and fetch, found
 * through the mapper's registration map. These tests drive the fetch side of
 * that map with a fake source, so a handler's render-time behaviour — and the
 * fact that a stored option string still reaches it — is pinned without a
 * kernel: `_default`, `_event`, `_self`, `_raw` and `_expand`, plus a
 * source-contributed `_view:`. The two collaborator-heavy handlers `_reference`
 * and `_render` are irreducibly integration-bound — one follows an entity
 * reference field through the `$field->entity` magic accessor a mock cannot
 * drive, the other runs a field through a formatter — so they stay pinned by
 * the children-match kernel tests and ViewsReferenceMappingFatalTest.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchHandlerInterface
 */
#[Group('neo_alchemist')]
class ChildrenMatchHandlerTest extends UnitTestCase {

  /**
   * The mapper under test.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
   */
  private ChildrenMatchMapper $mapper;

  /**
   * The event dispatcher the `_event` handler dispatches through.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcher
   */
  private EventDispatcher $dispatcher;

  /**
   * The child shape state the mapper writes its flags onto.
   *
   * @var \Drupal\neo_alchemist\ChildShapeState
   */
  private ChildShapeState $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->state = new ChildShapeState();
    // A real dispatcher so the `_event` handler has something to dispatch
    // through; a test adds its listener before mapping.
    $this->dispatcher = new EventDispatcher();
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
      $this->dispatcher,
      $this->createMock(ComponentValuePluginManagerInterface::class),
      $this->createMock(FormatterPluginManager::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    $this->mapper->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Event handler returns the value a subscriber sets on the event.
   */
  public function testEventHandlerReturnsTheSubscriberValue(): void {
    $this->dispatcher->addListener(
      ComponentValueEvent::EVENT_NAME,
      static fn (ComponentValueEvent $event) => $event->setValue('from the subscriber'),
    );
    $shape = $this->shape(['title'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$this->entity()]));

    $value = $this->mapper->getValues($source, $shape, $this->mapping('title', '_event'), NULL);

    $this->assertSame([0 => ['title' => 'from the subscriber']], $value);
  }

  /**
   * Self handler converts the iterated media entity via the child shape.
   */
  public function testSelfHandlerConvertsTheIteratedMediaEntity(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('image');

    // A real media shape is both a component shape (what getChildShapeById
    // returns) and a media shape (what the self handler tests for), so the
    // double is the intersection of the two.
    $childShape = $this->createMockForIntersectionOfInterfaces([
      ComponentShapePluginInterface::class,
      ComponentShapeMediaPluginInterface::class,
    ]);
    $childShape->method('getSupportedMediaTypes')->willReturn(['image']);
    $childShape->method('getValueFromMedia')->with($media)->willReturn(['target_id' => 7]);

    $shape = $this->shape(['pic'], TRUE);
    $shape->method('getValueResolverShape')->with('pic')->willReturn($childShape);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$media]));

    $value = $this->mapper->getValues($source, $shape, $this->mapping('pic', '_self'), NULL);

    $this->assertSame([0 => ['pic' => ['target_id' => 7]]], $value);
  }

  /**
   * Self handler on a non-media entity contributes nothing.
   */
  public function testSelfHandlerLeavesNonMediaEntityEmpty(): void {
    $shape = $this->shape(['pic'], TRUE);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$this->entity()]));

    // A non-media entity yields an empty child, and a delta holding only empty
    // children is dropped — so the whole list is empty, not filled.
    $value = $this->mapper->getValues($source, $shape, $this->mapping('pic', '_self'), NULL);

    $this->assertSame([], $value);
  }

  /**
   * Expand handler recurses the same entity onto the child's own children.
   *
   * The child decides the shape of the recursion, not the root: a non-iterable
   * child expands to a flat property map, keyed by its grandchild names.
   */
  public function testExpandHandlerRecursesOntoGrandchildShapes(): void {
    $wrapper = $this->createMock(ComponentShapeChildrenMatchPluginInterface::class);
    $wrapper->method('isIterable')->willReturn(FALSE);

    $shape = $this->shape(['wrapper'], TRUE);
    $shape->method('getValueResolverShape')->with('wrapper')->willReturn($wrapper);
    $source = new FakeChildrenMatchSource(ChildrenMatchResult::of([$this->entity(TRUE, 'Nested')]));

    $configuration = [
      'shape_fields' => [
        'wrapper' => [
          'field' => '_expand',
          'shape_fields' => [
            'title' => ['field' => '_entity:label'],
          ],
        ],
      ],
    ];

    $value = $this->mapper->getValues($source, $shape, $configuration, NULL);

    $this->assertSame(
      [0 => ['wrapper' => ['title' => ['Nested']]]],
      $value,
    );
  }

  /**
   * Stored option strings still resolve, each through its registered handler.
   *
   * The pin behind "no update hook": a saved `shape_fields` keeps the exact
   * strings it always had — `_default`, `_raw:boolean_true`, `_raw:string`,
   * `_entity:label` (a field-matcher key, claimed by nobody) and a
   * source-contributed `_view:` — and every one still lands on the right
   * handler after the extraction.
   */
  public function testStoredOptionStringsResolveThroughTheRegistry(): void {
    $shape = $this->shape(['title', 'flag', 'off', 'label', 'col', 'def'], TRUE);
    $source = new FakeChildrenMatchSource(
      ChildrenMatchResult::of([$this->entity(TRUE, 'Read from entity')]),
      ['view' => 'Rendered by the view'],
    );

    $configuration = [
      'shape_fields' => [
        'title' => ['field' => '_entity:label'],
        'flag' => ['field' => '_raw:boolean_true'],
        'off' => ['field' => '_raw:boolean_false'],
        'label' => ['field' => '_raw:string', 'string' => 'Literal'],
        'col' => ['field' => '_view:column'],
        'def' => ['field' => '_default'],
      ],
    ];

    $value = $this->mapper->getValues($source, $shape, $configuration, NULL);

    $this->assertSame([
      0 => [
        'title' => ['Read from entity'],
        'flag' => TRUE,
        'off' => FALSE,
        'label' => 'Literal',
        'col' => 'Rendered by the view',
      ],
    ], $value);
    // `_default` dropped its key so the child's own example survives.
    $this->assertTrue($this->state->getFlag('root~def', ChildShapeState::USE_DEFAULT));
    // The source was consulted for `_view:` and nothing else.
    $this->assertSame(['view'], $source->askedFor);
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
    $entity->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
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

}
