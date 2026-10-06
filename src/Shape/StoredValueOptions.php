<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

/**
 * Builds the options a stored prop value implies when it was saved without any.
 *
 * A placement stores each prop as `{ref, value, options}`, and the options are
 * what decide whether the value is shown at all: a shape with no options entry
 * of its own takes the ones the component's Default Value plugin configured
 * (often `empty`, so new placements start hidden) or its class's starting
 * values (ImageShape starts on `default`). The editor always writes the
 * `options` key, so editor-saved data never reaches that fallback by accident.
 *
 * Code writing a tree by hand usually leaves `options` out entirely. Its value
 * is then stored but never rendered: the prop resolves to hidden, or to the
 * component's default, and nothing says why. This class closes that gap. When
 * the `options` key is ABSENT, the stored value itself is the author's
 * decision, so every non-empty value in the prop's subtree gets
 * `{empty: 0, default: 0}` — exactly what the editor writes for a value an
 * author typed in.
 *
 * What it deliberately leaves alone:
 *
 * - An `options` key that is present, even an empty array. That is how the
 *   editor stores a prop, and its choices (Hide, Use default) stand.
 * - Empty values. They keep whatever fallback they had, so a value left blank
 *   in code behaves as it does today.
 * - Locks. The options are merged as instance options always have been, under
 *   anything already saved, and a locked shape ignores its instance value
 *   regardless of options.
 *
 * Keys follow ComponentShapePluginBase::id(): a child is its parent's id joined
 * by `~`, and a row child of an iterable carries the row's delta after its
 * name — `items~image~0`, `items~title~0~title`. The walk follows the resolved
 * schema and only descends into keys the stored value actually has, so a shape
 * that stores its own field item (an image's `{target_id}`) simply ends the
 * walk.
 *
 * Stateless, so it can be asserted directly without a container.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginManager::getInstancesFromSchema()
 */
final class StoredValueOptions {

  /**
   * The separator between the segments of a shape id.
   */
  private const SEPARATOR = '~';

  /**
   * Builds the options for a prop stored without an `options` key.
   *
   * @param string $id
   *   The prop's shape id: its name.
   * @param array $schema
   *   The prop's resolved schema.
   * @param mixed $value
   *   The stored value.
   *
   * @return array
   *   Shape id => option name => value, ready for NestedOptionMap::merge().
   */
  public function build(string $id, array $schema, mixed $value): array {
    $options = [];
    $this->walk($id, $schema, $value, $options);
    return $options;
  }

  /**
   * Records options for one shape and descends into its stored children.
   *
   * @param string $id
   *   The shape id.
   * @param array $schema
   *   The shape's resolved schema.
   * @param mixed $value
   *   The stored value at this shape.
   * @param array $options
   *   The options collected so far.
   */
  private function walk(string $id, array $schema, mixed $value, array &$options): void {
    if ($this->isEmpty($value)) {
      return;
    }
    $options[$id] = [
      NestedOptionMap::OPTION_EMPTY => 0,
      NestedOptionMap::OPTION_DEFAULT => 0,
    ];
    if (!is_array($value)) {
      return;
    }
    $types = (array) ($schema['type'] ?? []);

    // An iterable stores a list of rows; each row child's id carries the row's
    // delta after the child's name.
    if (in_array('array', $types, TRUE) && isset($schema['items']['properties']) && array_is_list($value)) {
      foreach ($value as $delta => $row) {
        if (!is_array($row)) {
          continue;
        }
        foreach ($schema['items']['properties'] as $name => $childSchema) {
          if (array_key_exists($name, $row)) {
            $this->walk($id . self::SEPARATOR . $name . self::SEPARATOR . $delta, (array) $childSchema, $row[$name], $options);
          }
        }
      }
      return;
    }

    if (isset($schema['properties']) && is_array($schema['properties'])) {
      foreach ($schema['properties'] as $name => $childSchema) {
        if (array_key_exists($name, $value)) {
          $this->walk($id . self::SEPARATOR . $name, (array) $childSchema, $value[$name], $options);
        }
      }
    }
  }

  /**
   * Whether a stored value carries no content.
   *
   * Mirrors the value pipeline's contract rather than PHP truthiness: 0, '0'
   * and FALSE are values. A field-item wrapper (`{value, format}`) is as empty
   * as the value it wraps, so a text format alone does not count as content.
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return bool
   *   TRUE when there is nothing to show.
   */
  private function isEmpty(mixed $value): bool {
    if ($value === NULL || $value === '') {
      return TRUE;
    }
    if (!is_array($value)) {
      return FALSE;
    }
    if (array_key_exists('value', $value)) {
      return $this->isEmpty($value['value']);
    }
    foreach ($value as $child) {
      if (!$this->isEmpty($child)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
