<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

final class ReferenceFieldPropExpression implements StructuredDataPropExpressionInterface {

  use CompoundExpressionTrait;

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferenceFieldPropExpression|FieldPropExpression|FieldObjectPropsExpression $referenced,
  ) {}

  public function __toString(): string {
    return static::PREFIX
      . self::withoutPrefix((string) $this->referencer)
      . self::PREFIX_ENTITY_LEVEL
      . self::withoutPrefix((string) $this->referenced);
  }

  public function withDelta(int $delta): static {
    return new static(
      $this->referencer->withDelta($delta),
      $this->referenced,
    );
  }

  public static function fromString(string $representation): static {
    $parts = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_ENTITY_LEVEL, $representation);
    $referencer = FieldPropExpression::fromString($parts[0]);
    // @todo detect and support ReferenceFieldPropExpression + FieldObjectPropsExpression
    $referenced = FieldPropExpression::fromString(static::PREFIX . static::PREFIX_ENTITY_LEVEL . $parts[1]);
    return new static($referencer, $referenced);
  }

  public function isSupported(EntityInterface|FieldItemInterface $entity): bool {
    assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->referencer->entityType->getEntityTypeId();
    $expected_bundle = $this->referencer->entityType->getBundles()[0] ?? $expected_entity_type_id;
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    if ($entity->bundle() !== $expected_bundle) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, bundle `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, $expected_bundle, $entity->bundle()));
    }
    // @todo validate that the field exists?
    return TRUE;
  }

}
