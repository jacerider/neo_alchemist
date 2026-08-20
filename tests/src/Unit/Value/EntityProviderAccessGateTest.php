<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Match\MatcherField;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityHasValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the "entity is not new" gate shared by the entity providers.
 *
 * EntityValue and EntityHasValue both refuse to run against an unsaved
 * entity, with byte-identical logic:
 *
 * @code
 * match($scope) {
 *   'field' => match($op) { 'default' => !$entity->isNew(), default => TRUE },
 *   default => !$entity->isNew(),
 * }
 * @endcode
 *
 * Two things make this worth pinning. It is subtle — in FIELD scope only the
 * `default` op is gated, everything else runs even for a new entity, because
 * the field-config UI has to build its forms before any entity exists. And it
 * is silent in both directions: invert it and entity providers either start
 * pulling values off a placeholder entity in previews, or stop resolving on
 * real pages entirely.
 *
 * The two implementations are asserted to AGREE, so a fix applied to one and
 * not the other fails here.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityValue::isAllowed()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityHasValue::isAllowed()
 */
#[Group('neo_alchemist')]
class EntityProviderAccessGateTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Builds a shape reporting the given scope and entity state.
   *
   * The gate asks the shape two questions and both are the context role's —
   * what scope this is and what entity it is attached to — so that is the only
   * role the double answers for.
   */
  private function shape(string $scope, bool $entityIsNew): ComponentShapePluginInterface {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isNew')->willReturn($entityIsNew);

    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getScope')->willReturn($scope);
    $context->method('getEntity')->willReturn($entity);
    return $this->shapeDouble([$context]);
  }

  /**
   * A real MatcherField over mocked services.
   *
   * The gate never touches it for the ops under test — only EntityValue's
   * separate `manage` arm does — but the constructor requires one, and the
   * class is final so it cannot be mocked.
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
   * The gate's truth table.
   *
   * @return array
   *   Cases of [scope, op, entity is new, allowed].
   */
  public static function gateCases(): array {
    return [
      // Field scope gates only the default op, so the field-config UI can
      // still build its forms before any entity exists.
      'field + default + new entity => blocked' => ['field', 'default', TRUE, FALSE],
      'field + default + saved entity => allowed' => ['field', 'default', FALSE, TRUE],
      'field + value + new entity => allowed' => ['field', 'value', TRUE, TRUE],
      'field + init + new entity => allowed' => ['field', 'init', TRUE, TRUE],
      'field + edit + new entity => allowed' => ['field', 'edit', TRUE, TRUE],
      // Entity and config scope gate every op on the entity being saved.
      'entity + default + new entity => blocked' => ['entity', 'default', TRUE, FALSE],
      'entity + default + saved entity => allowed' => ['entity', 'default', FALSE, TRUE],
      'entity + value + new entity => blocked' => ['entity', 'value', TRUE, FALSE],
      'entity + value + saved entity => allowed' => ['entity', 'value', FALSE, TRUE],
      'config + default + new entity => blocked' => ['config', 'default', TRUE, FALSE],
      'config + value + saved entity => allowed' => ['config', 'value', FALSE, TRUE],
    ];
  }

  /**
   * Both providers gate identically on the entity's saved state.
   */
  #[DataProvider('gateCases')]
  public function testGate(string $scope, string $op, bool $entityIsNew, bool $allowed): void {
    $entityValue = new EntityValue('entity', [], $this->shape($scope, $entityIsNew), [], $this->matcherField());
    $entityHasValue = new EntityHasValue('entity_has_value', [], $this->shape($scope, $entityIsNew), [], $this->matcherField());

    $this->assertSame($allowed, $entityValue->isAllowed($op), 'EntityValue gated as expected.');
    $this->assertSame(
      $entityValue->isAllowed($op),
      $entityHasValue->isAllowed($op),
      'The two providers must gate identically — a fix applied to one belongs on both.',
    );
  }

  /**
   * Neither provider is author-editable.
   */
  public function testNeitherIsEditable(): void {
    $shape = $this->shape('entity', FALSE);

    $this->assertFalse((new EntityValue('entity', [], $shape, [], $this->matcherField()))->isEditable());
    $this->assertFalse((new EntityHasValue('entity_has_value', [], $shape, [], $this->matcherField()))->isEditable());
  }

}
