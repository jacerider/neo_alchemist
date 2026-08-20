<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapeContextInterface;
use Drupal\neo_alchemist\ComponentShapeExpansionInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentShapeSchemaInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityFilterValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityLoadValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The entity providers pass the threaded value through when they cannot act.
 *
 * Every provider in the pipeline receives the value produced so far and is
 * expected to return it untouched when it has nothing to contribute — the
 * alternative is wiping an earlier provider's work to NULL, which is the
 * silent-data-loss shape this suite exists for. EntityLoadValue's own comment
 * names it: "Can't act: pass the threaded value through rather than wiping it
 * to NULL."
 *
 * Each provider has several ways to be unable to act — wrong shape type,
 * missing configuration, a target that no longer exists — and they are easy
 * to regress independently, so each gets its own pin.
 *
 * One deliberate asymmetry is recorded here: EntityReferenceValue RESETS to []
 * once an entity key is configured, rather than passing through, because a
 * configured-but-unresolvable reference means "this component has nothing to
 * show" rather than "I have no opinion".
 */
#[Group('neo_alchemist')]
class EntityProviderPassThroughTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * A real MatcherField over mocked services (the class is final).
   */
  private function matcherField(): MatcherField {
    return new MatcherField(
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(EntityTypeBundleInfoInterface::class),
      $this->createMock(RouteProviderInterface::class),
    );
  }

  /**
   * A real ChildrenMatchMapper over mocked collaborators.
   *
   * These tests only assert pass-through and editability, so nothing reaches
   * the mapping — but the producers now declare the mapper as a constructor
   * argument, which is the point of the inversion.
   */
  private function childrenMatchMapper(): ChildrenMatchMapper {
    return new ChildrenMatchMapper(
      $this->matcherField(),
      $this->matcherReference(),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(ComponentValuePluginManagerInterface::class),
      $this->createMock(FormatterPluginManager::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
  }

  /**
   * A real MatcherReference over mocked services (the class is final).
   */
  private function matcherReference(): MatcherReference {
    return new MatcherReference(
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(EntityTypeBundleInfoInterface::class),
    );
  }

  /**
   * Builds an EntityLoadValue with the given shape, config and storage.
   */
  private function loadValue(ComponentShapePluginInterface $shape, array $configuration, ?EntityStorageInterface $storage = NULL): EntityLoadValue {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage ?? $this->createMock(EntityStorageInterface::class));

    return new EntityLoadValue(
      'entity_load',
      [],
      $shape,
      $configuration,
      $entityTypeManager,
      $this->childrenMatchMapper(),
    );
  }

  /**
   * A shape that supports children matching.
   *
   * The providers only test the capability with instanceof, so the double
   * carries it and answers for nothing else unless a component is passed —
   * which is the one thing EntityFilterValue asks its shape for, and belongs
   * to the context role.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface|null $component
   *   The component the shape reports, when the test needs one.
   */
  private function matchShape(?ComponentInterface $component = NULL): ComponentShapeChildrenMatchPluginInterface {
    $roles = [];
    if ($component !== NULL) {
      $context = $this->shapeRole(ComponentShapeContextInterface::class);
      $context->method('getComponent')->willReturn($component);
      $roles[] = $context;
    }

    $shape = $this->shapeDouble($roles, [ComponentShapeChildrenMatchPluginInterface::class]);
    assert($shape instanceof ComponentShapeChildrenMatchPluginInterface);
    return $shape;
  }

  /**
   * EntityLoadValue: a shape that cannot match children is left alone.
   */
  public function testLoadValueIgnoresNonMatchShape(): void {
    $plugin = $this->loadValue(
      $this->unusedShape(),
      ['entity_type' => 'node', 'entity_id' => 1],
    );

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityLoadValue: incomplete configuration passes the value through.
   */
  public function testLoadValueIgnoresIncompleteConfiguration(): void {
    $noType = $this->loadValue($this->matchShape(), ['entity_type' => '', 'entity_id' => 1]);
    $noId = $this->loadValue($this->matchShape(), ['entity_type' => 'node', 'entity_id' => '']);

    $this->assertSame('THREADED', $noType->provideDefaultValue('THREADED'));
    $this->assertSame('THREADED', $noId->provideDefaultValue('THREADED'));
  }

  /**
   * EntityLoadValue: a target that no longer exists passes the value through.
   *
   * A deleted entity must not wipe an earlier provider's value — the
   * component keeps whatever else the pipeline produced.
   */
  public function testLoadValueIgnoresMissingEntity(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $plugin = $this->loadValue($this->matchShape(), ['entity_type' => 'node', 'entity_id' => 99], $storage);

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityFilterValue: a shape that cannot match children is left alone.
   */
  public function testFilterValueIgnoresNonMatchShape(): void {
    $plugin = new EntityFilterValue(
      'entity_filter',
      [],
      $this->unusedShape(),
      ['filter' => 'some-filter'],
      $this->matcherReference(),
      $this->childrenMatchMapper(),
    );

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityFilterValue: a filter that no longer exists passes through.
   */
  public function testFilterValueIgnoresMissingFilter(): void {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('getFilter')->willReturn(NULL);
    $shape = $this->matchShape($component);

    $plugin = new EntityFilterValue(
      'entity_filter',
      [],
      $shape,
      ['filter' => 'deleted-filter'],
      $this->matcherReference(),
      $this->childrenMatchMapper(),
    );

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityReferenceValue: with no entity key configured the value survives.
   */
  public function testReferenceValueWithoutKeyPassesThrough(): void {
    $plugin = new EntityReferenceValue(
      'entity_reference',
      [],
      $this->unusedShape(),
      ['entity' => ''],
      $this->matcherReference(),
      $this->childrenMatchMapper(),
    );

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityReferenceValue needs a target entity type AND a distributable shape.
   *
   * Mirrors entity_query's gate: iterable lists and expandable objects (the
   * `_aggregate` shape of an aggregated component) can receive a children
   * distribution; non-expandable object shapes such as link and heading
   * cannot, and must not be offered the plugin.
   */
  public function testReferenceValueApplicability(): void {
    $iterable = $this->distributableShape('node', iterable: TRUE);
    $expandable = $this->distributableShape('node', iterable: FALSE, expandable: TRUE);
    $flat = $this->distributableShape('node');
    $withoutTarget = $this->distributableShape(NULL, iterable: TRUE);

    $this->assertTrue(EntityReferenceValue::isApplicable($iterable), 'An iterable (array) shape is offered the plugin.');
    $this->assertTrue(EntityReferenceValue::isApplicable($expandable), 'An expandable object (aggregate) shape is offered the plugin.');
    $this->assertFalse(EntityReferenceValue::isApplicable($flat), 'A non-distributable shape is not.');
    $this->assertFalse(EntityReferenceValue::isApplicable($withoutTarget), 'No target entity type, no offer.');
  }

  /**
   * None of the entity providers are author-editable.
   */
  public function testNoneAreEditable(): void {
    $shape = $this->unusedShape();

    $this->assertFalse($this->loadValue($shape, [])->isEditable());
    $this->assertFalse((new EntityReferenceValue('entity_reference', [], $shape, [], $this->matcherReference(), $this->childrenMatchMapper()))->isEditable());
  }

  /**
   * A shape reporting what the applicability gate reads, and nothing else.
   *
   * Three roles, one question each: the target entity type is context, being
   * iterable is schema, being expandable is expansion. Naming them is what
   * keeps a stub for one from being accepted against another.
   *
   * @param string|null $targetEntityType
   *   The entity type the shape targets, or NULL for none.
   * @param bool $iterable
   *   Whether the shape is an iterable list.
   * @param bool $expandable
   *   Whether the shape is an expandable object.
   */
  private function distributableShape(?string $targetEntityType, bool $iterable = FALSE, bool $expandable = FALSE): ComponentShapePluginInterface {
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getTargetEntityType')->willReturn($targetEntityType);
    $schema = $this->shapeRole(ComponentShapeSchemaInterface::class);
    $schema->method('isIterable')->willReturn($iterable);
    $expansion = $this->shapeRole(ComponentShapeExpansionInterface::class);
    $expansion->method('isExpandable')->willReturn($expandable);

    return $this->shapeDouble([$context, $schema, $expansion]);
  }

}
