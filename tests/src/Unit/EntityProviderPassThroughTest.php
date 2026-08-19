<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityFilterValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityLoadValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue;
use Drupal\Tests\UnitTestCase;
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
      $this->createMock(EventDispatcherInterface::class),
      $this->matcherField(),
      $this->matcherReference(),
    );
  }

  /**
   * A shape that supports children matching.
   *
   * ComponentShapeChildrenMatchPluginInterface already extends the shape
   * interface, so it is mocked directly — intersecting the two would fail
   * with "Interfaces must not declare the same method". (Contrast the media
   * and style interfaces, which are standalone markers and DO need an
   * intersection mock.)
   */
  private function matchShape(): ComponentShapeChildrenMatchPluginInterface {
    return $this->createMock(ComponentShapeChildrenMatchPluginInterface::class);
  }

  /**
   * EntityLoadValue: a shape that cannot match children is left alone.
   */
  public function testLoadValueIgnoresNonMatchShape(): void {
    $plugin = $this->loadValue(
      $this->createMock(ComponentShapePluginInterface::class),
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
      $this->createMock(ComponentShapePluginInterface::class),
      ['filter' => 'some-filter'],
      $this->matcherField(),
      $this->matcherReference(),
    );

    $this->assertSame('THREADED', $plugin->provideDefaultValue('THREADED'));
  }

  /**
   * EntityFilterValue: a filter that no longer exists passes through.
   */
  public function testFilterValueIgnoresMissingFilter(): void {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('getFilter')->willReturn(NULL);
    $shape = $this->matchShape();
    $shape->method('getComponent')->willReturn($component);

    $plugin = new EntityFilterValue(
      'entity_filter',
      [],
      $shape,
      ['filter' => 'deleted-filter'],
      $this->matcherField(),
      $this->matcherReference(),
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
      $this->createMock(ComponentShapePluginInterface::class),
      ['entity' => ''],
      $this->matcherField(),
      $this->matcherReference(),
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
    $iterable = $this->createMock(ComponentShapePluginInterface::class);
    $iterable->method('getTargetEntityType')->willReturn('node');
    $iterable->method('isIterable')->willReturn(TRUE);

    $expandable = $this->createMock(ComponentShapePluginInterface::class);
    $expandable->method('getTargetEntityType')->willReturn('node');
    $expandable->method('isIterable')->willReturn(FALSE);
    $expandable->method('isExpandable')->willReturn(TRUE);

    $flat = $this->createMock(ComponentShapePluginInterface::class);
    $flat->method('getTargetEntityType')->willReturn('node');

    $withoutTarget = $this->createMock(ComponentShapePluginInterface::class);
    $withoutTarget->method('getTargetEntityType')->willReturn(NULL);
    $withoutTarget->method('isIterable')->willReturn(TRUE);

    $this->assertTrue(EntityReferenceValue::isApplicable($iterable), 'An iterable (array) shape is offered the plugin.');
    $this->assertTrue(EntityReferenceValue::isApplicable($expandable), 'An expandable object (aggregate) shape is offered the plugin.');
    $this->assertFalse(EntityReferenceValue::isApplicable($flat), 'A non-distributable shape is not.');
    $this->assertFalse(EntityReferenceValue::isApplicable($withoutTarget), 'No target entity type, no offer.');
  }

  /**
   * None of the entity providers are author-editable.
   */
  public function testNoneAreEditable(): void {
    $shape = $this->createMock(ComponentShapePluginInterface::class);

    $this->assertFalse($this->loadValue($shape, [])->isEditable());
    $this->assertFalse((new EntityReferenceValue('entity_reference', [], $shape, [], $this->matcherField(), $this->matcherReference()))->isEditable());
  }

}
