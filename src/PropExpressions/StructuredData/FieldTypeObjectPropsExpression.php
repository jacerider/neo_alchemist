<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
final class FieldTypeObjectPropsExpression implements StructuredDataPropExpressionInterface {

  use CompoundExpressionTrait;

  /**
   * Constructs a new FieldTypeObjectPropsExpression.
   *
   * @param string $fieldType
   *   A field type.
   * @param array<string, \Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\neo_alchemist\PropExpressions\StructuredData\ReferenceFieldTypePropExpression> $objectPropsToFieldTypeProps
   *   A mapping of SDC prop names to Field Type prop expressions.
   */
  public function __construct(
    public readonly string $fieldType,
    public readonly array $objectPropsToFieldTypeProps,
  ) {
    assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldTypeProps)));
    assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldTypePropExpression || $expr instanceof ReferenceFieldTypePropExpression;
    }, $this->objectPropsToFieldTypeProps));
  }

  public function __toString(): string {
    return static::PREFIX
      . $this->fieldType
      . static::PREFIX_PROPERTY_LEVEL . static::PREFIX_OBJECT
      . implode(',', array_map(
        fn (string $obj_prop_name, FieldTypePropExpression|ReferenceFieldTypePropExpression $expr) => sprintf('%s%s%s',
          $obj_prop_name,
          $expr instanceof ReferenceFieldTypePropExpression
            ? self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE
            : self::SYMBOL_OBJECT_MAPPED_USE_PROP,
          $expr instanceof ReferenceFieldTypePropExpression
            ? $expr->referencer->propName . self::PREFIX_ENTITY_LEVEL . self::withoutPrefix((string) $expr->referenced)
            : $expr->propName,
        ),
        array_keys($this->objectPropsToFieldTypeProps),
        array_values($this->objectPropsToFieldTypeProps),
      ))
      . static::SUFFIX_OBJECT;
  }

  public static function fromString(string $representation): static {
    [$field_type, $object_mapping] = explode(self::PREFIX_PROPERTY_LEVEL, mb_substr($representation, 2), 2);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, self::SYMBOL_OBJECT_MAPPED_USE_PROP)) {
        [$sdc_obj_prop_name, $field_type_prop_name] = explode(self::SYMBOL_OBJECT_MAPPED_USE_PROP, $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldTypePropExpression($field_type, $field_type_prop_name);
      }
      else {
        [$sdc_obj_prop_name, $remainder] = explode(self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE, $obj_prop_mapping);
        [$field_type_prop_name, $remainder] = explode(self::PREFIX_ENTITY_LEVEL, $remainder, 2);
        $referenced = StructuredDataPropExpression::fromString(static::PREFIX . $remainder);
        assert($referenced instanceof FieldPropExpression || $referenced instanceof ReferenceFieldPropExpression);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new ReferenceFieldTypePropExpression(
          new FieldTypePropExpression($field_type, $field_type_prop_name),
          $referenced
        );
      }
    }

    return new static($field_type, $objectPropsToFieldTypeProps);
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}
