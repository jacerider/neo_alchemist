<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Component\Render\MarkupInterface;

/**
 * Describes the prop: what the component's schema says about it.
 *
 * Answers "what kind of prop is this" without resolving a value or touching
 * the entity. This is what a matcher, a query or a preview map reads.
 *
 * The JSON-schema type constants live here because `getType()` returns one of
 * them. They are inherited by ComponentShapePluginInterface, so the existing
 * `ComponentShapePluginInterface::STRING` spelling still resolves.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeSchemaInterface extends ComponentShapeIdentityInterface {

  const STRING = 'string';
  const NUMBER = 'number';
  const INTEGER = 'integer';
  const BOOLEAN = 'boolean';
  const ARRAY = 'array';
  const OBJECT = 'object';

  /**
   * Get the schema.
   *
   * @return array
   *   The schema.
   */
  public function getSchema(): array;

  /**
   * Get the prop type.
   *
   * This is the type of the prop.
   *
   * Can be 'string', 'number', 'integer', 'boolean', 'array', 'object'.
   *
   * @return string
   *   The prop name.
   */
  public function getType(): string;

  /**
   * Get the prop ref.
   *
   * This is the reference to the prop.
   *
   * @return string
   *   The prop ref.
   */
  public function getRef(): string;

  /**
   * Get the prop format.
   *
   * This is the optional format of the prop.
   *
   * @return string
   *   The prop format.
   */
  public function getFormat(): string;

  /**
   * Get the prop description.
   *
   * This is the user-facing description of the prop.
   *
   * @return string
   *   The prop description.
   */
  public function getDescription(): string|MarkupInterface;

  /**
   * Get the prop properties.
   *
   * This is the properties of the prop.
   *
   * @return array
   *   The prop properties.
   */
  public function getProperties(): array;

  /**
   * Retrieves the field options from the schema.
   *
   * This method checks if the 'enum' key exists in the schema array. If it
   * does, it returns the associated value, which is expected to be an array of
   * options. If the 'enum' key does not exist, it returns NULL.
   *
   * @return array
   *   An array of field options if the 'enum' key exists in the schema, or NULL
   *   if the 'enum' key is not present.
   */
  public function getFieldOptions(): array;

  /**
   * Converts the component shape structure to a string expression.
   *
   * This method takes the structure of the component shape and converts it
   * into a string expression where each key-value pair is concatenated with
   * a period (.) and each pair is separated by a colon (:).
   *
   * @return string
   *   The string expression representing the component shape structure.
   */
  public function getExpression(): string;

  /**
   * Check if shape is iterable.
   *
   * @return bool
   *   Returns TRUE if the shape is iterable, FALSE otherwise.
   */
  public function isIterable(): bool;

}
