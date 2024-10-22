<?php

/**
 * @file
 * Sdc prop schema type.
 */

declare(strict_types=1);

namespace Drupal\neo_alchemist\JsonSchemaInterpreter;

use Drupal\neo_alchemist\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements;

/**
 * Sdc prop schema type.
 *
 * @phpstan-type JsonSchema array<string, mixed>
 *
 * phpcs:disable Drupal.Files.LineLength.TooLong
 */
enum SdcPropJsonSchemaType : string {
  case STRING = 'string';
  case NUMBER = 'number';
  case INTEGER = 'integer';
  case OBJECT = 'object';
  case ARRAY = 'array';
  case BOOLEAN = 'boolean';

  /**
   * Check if the shape is scalar.
   *
   * @return bool
   *   Returns TRUE if the shape is scalar, FALSE otherwise.
   */
  public function isScalar(): bool {
    return match ($this) {
      // A subset of the "primitive types" in JSON schema are:
      // - "scalar values" in PHP terminology
      // - "primitives" in Drupal Typed data terminology.
      // @see https://www.php.net/manual/en/function.is-scalar.php
      // @see \Drupal\Core\TypedData\PrimitiveInterface
      self::STRING, self::NUMBER, self::INTEGER, self::BOOLEAN => TRUE,
      // Another subset of the "primitive types" in JSON schema are:
      // - "non-scalar values" in PHP terminology, specifically "iterable"
      // - "traversable" in Drupal Typed Data terminology, specifically "lists"
      //   ("sequences" in config schema) or "complex data" ("mappings" in
      //   config schema)
      // @see https://www.php.net/manual/en/function.is-iterable.php
      // @see \Drupal\Core\TypedData\ListInterface
      // @see \Drupal\Core\TypedData\ComplexDataInterface
      // @see \Drupal\Core\TypedData\TraversableTypedDataInterface
      self::ARRAY, self::OBJECT => FALSE,
    };
  }

  /**
   * Check if the shape is iterable.
   *
   * @return bool
   *   Returns TRUE if the shape is iterable, FALSE otherwise.
   */
  public function isIterable(): bool {
    return !$this->isScalar();
  }

  /**
   * Check if the shape is traversable.
   *
   * @return bool
   *   Returns TRUE if the shape is traversable, FALSE otherwise.
   */
  public function isTraversable(): bool {
    return !$this->isScalar();
  }

  /**
   * Maps the given schema to data type shape requirements.
   *
   * Used for matching against existing field instances, to find candidate
   * dynamic prop source expressions that return a value that fits in this prop
   * shape.
   *
   * @param array $schema
   *   The prop json schema.
   *
   * @see \Drupal\neo_alchemist\PropSource\DynamicPropSource
   * @see \Drupal\neo_alchemist\SdcPropToFieldTypePropMatcher
   *
   * @return \Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement|\Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirements|false
   *   The data type shape requirements.
   */
  public function toDataTypeShapeRequirements(array $schema): DataTypeShapeRequirement|DataTypeShapeRequirements|false {
    return match ($this) {
      // There cannot possibly be any additional validation for booleans.
      SdcPropJsonSchemaType::BOOLEAN => FALSE,

      // The `string` JSON schema type
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `minLength` and `maxLength`: https://json-schema.org/understanding-json-schema/reference/string#length
      // - `pattern`: https://json-schema.org/understanding-json-schema/reference/string#regexp
      // - `format`: https://json-schema.org/understanding-json-schema/reference/string#format and https://json-schema.org/understanding-json-schema/reference/string#built-in-formats
      SdcPropJsonSchemaType::STRING => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirement('Choice', [
          'choices' => $schema['enum'],
        ], NULL),
        array_key_exists('pattern', $schema) && array_key_exists('format', $schema) => new DataTypeShapeRequirements([
          JsonSchemaStringFormat::from($schema['format'])->toDataTypeShapeRequirements(),
          // TRICKY: `pattern` in JSON schema requires no start/end delimiters,
          // but `preg_match()` in PHP does.
          // @see https://json-schema.org/understanding-json-schema/reference/regular_expressions
          // @see \Symfony\Component\Validator\Constraints\Regex
          new DataTypeShapeRequirement('Regex', ['pattern' => '/' . str_replace('/', '\/', $schema['pattern']) . '/']),
        ]),
        // TRICKY: `pattern` in JSON schema requires no start/end delimiters,
        // but `preg_match()` in PHP does.
        // @see https://json-schema.org/understanding-json-schema/reference/regular_expressions
        // @see \Symfony\Component\Validator\Constraints\Regex
        array_key_exists('pattern', $schema) => new DataTypeShapeRequirement('Regex', ['pattern' => '/' . str_replace('/', '\/', $schema['pattern']) . '/']),
        array_key_exists('format', $schema) => JsonSchemaStringFormat::from($schema['format'])->toDataTypeShapeRequirements(),
        // Otherwise, it's an unrestricted string. Simply surfacing all
        // structured data containing strings would be meaningless though. To
        // ensure a good UX, Drupal interprets this as meaning "prose".
        // @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\StringSemanticsConstraint::PROSE
        TRUE => new DataTypeShapeRequirement('StringSemantics', ['semantic' => StringSemanticsConstraint::PROSE]),
      },

      // phpcs:disable Drupal.Files.LineLength.TooLong
      // The `integer` and `number` JSON schema types.
      // - `enum`: https://json-schema.org/understanding-json-schema/reference/enum
      // - `multipleOf`: https://json-schema.org/understanding-json-schema/reference/numeric#multiples
      // - `minimum`, `exclusiveMinimum`, `maximum` and `exclusiveMaximum`: https://json-schema.org/understanding-json-schema/reference/numeric#range
      // phpcs:enable
      SdcPropJsonSchemaType::INTEGER, SdcPropJsonSchemaType::NUMBER => match (TRUE) {
        array_key_exists('enum', $schema) => new DataTypeShapeRequirement('Choice', [
          'choices' => $schema['enum'],
        ], NULL),
        // Both min & max.
        array_key_exists('minimum', $schema) && array_key_exists('maximum', $schema) => new DataTypeShapeRequirement('Range', [
          'min' => $schema['minimum'],
          'max' => $schema['maximum'],
        ], NULL),
        // Either min or max.
        array_key_exists('minimum', $schema) => new DataTypeShapeRequirement('Range', ['min' => $schema['minimum']], NULL),
        array_key_exists('maximum', $schema) => new DataTypeShapeRequirement('Range', ['min' => $schema['minimum']], NULL),
        !empty(array_intersect([
          'multipleOf',
          'maximum',
          'exclusiveMinimum',
          'exclusiveMaximum',
        ], array_keys($schema))) => new DataTypeShapeRequirement('NOT YET SUPPORTED', []),
        // Otherwise, it's an unrestricted integer or number.
        TRUE => FALSE,
      },

      SdcPropJsonSchemaType::OBJECT, SdcPropJsonSchemaType::ARRAY => (function () {
        throw new \LogicException('@see ::computeStorablePropShape() and ::recurseJsonSchema()');
      })(),
    };
  }

}
