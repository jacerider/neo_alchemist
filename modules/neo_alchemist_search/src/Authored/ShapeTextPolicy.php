<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Authored;

use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeInterablePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginManager;
use Drupal\neo_alchemist\Shape\ComponentShapeRegionPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;

/**
 * Asks shapes which parts of their values are human-readable text.
 *
 * Stored prop values cannot answer this themselves: a heading title is
 * `{"value": "Create a better world"}` and a button style is
 * `{"value": "primary"}`. Only the shape behind the value knows the difference,
 * so every decision here is put to the shape rather than inferred from the
 * payload.
 *
 * Shapes declare their own text through the `text_keys` property of the
 * ComponentShape attribute, and their structure through the marker interfaces
 * they implement. Nothing is enumerated here, because a shape can arrive from
 * any module and its value keys are its own business — the same reasoning that
 * made presentational keys a per-shape declaration rather than a shared list.
 *
 * A shape that declares nothing yields nothing. That direction is deliberate:
 * a missing string is visible to whoever goes looking, whereas indexed CSS
 * tokens quietly rot relevance for everyone and nobody thinks to check.
 *
 * Nothing here instantiates a shape. Definitions are cached arrays and `is_a()`
 * on a class string autoloads without constructing, so classification costs no
 * container work on the indexing path.
 *
 * @see \Drupal\neo_alchemist\Attribute\ComponentShape::$text_keys
 */
final class ShapeTextPolicy {

  /**
   * Marker interfaces whose implementors can never yield text.
   *
   * The safety net beneath the declarations: a shape extending the style base
   * cannot leak a colour token into an index by declaring text_keys carelessly,
   * and one wrapping a media reference cannot leak an entity id. Region
   * children live in the component tree rather than the prop value, so
   * descending into one would emit UUIDs.
   *
   * Deliberately not alterable — a net that can be switched off is not one.
   */
  private const BARRED_INTERFACES = [
    ComponentShapeStylePluginInterface::class,
    ComponentShapeMediaPluginInterface::class,
    ComponentShapeRegionPluginInterface::class,
  ];

  /**
   * Memoised interface checks, keyed by shape id then interface.
   *
   * @var array<string, array<string, bool>>
   */
  private array $implements = [];

  /**
   * Constructs a ShapeTextPolicy.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginManager $shapeManager
   *   Used for definition lookups only; no shape is ever instantiated.
   */
  public function __construct(
    private readonly ComponentShapePluginManager $shapeManager,
  ) {}

  /**
   * Resolves the shape id for a schema node.
   *
   * Resolution is by `ref` first and `type` only as a fallback, matching
   * ComponentShapePluginManager::getInstance(). Type-first resolution is not
   * merely less precise, it is wrong: `icon` and `slug` declare `type: string`
   * and would have their machine names indexed as prose, while `image_size`
   * declares `type: array` and would be recursed into as a list.
   *
   * @param array $node
   *   A node of the component's prop schema.
   * @param string|null $storedRef
   *   The `ref` recorded alongside the stored value, when there is one. It wins
   *   over the schema so a value survives a schema that has drifted.
   *
   * @return string
   *   A shape id, or an empty string when the node names none.
   */
  public function shapeId(array $node, ?string $storedRef = NULL): string {
    foreach ([$storedRef, $node['ref'] ?? NULL] as $candidate) {
      if (is_string($candidate) && $candidate !== '' && $this->shapeManager->hasDefinition($candidate)) {
        return $candidate;
      }
    }
    $type = $node['type'] ?? '';
    if (is_array($type)) {
      $type = reset($type) ?: '';
    }
    return is_string($type) ? $type : '';
  }

  /**
   * Whether a schema node declares a closed set of values.
   *
   * A component author can give a plain `type: string` prop an `enum`, and a
   * style prop carries `styles`. Both are machine tokens wearing a text shape's
   * clothes, and this is the only signal separating them.
   *
   * This stays a fact about the component's schema rather than the shape,
   * because it is the component author's choice, not the shape's.
   *
   * @param array $node
   *   A node of the component's prop schema.
   *
   * @return bool
   *   TRUE when the node's values are a fixed vocabulary.
   */
  public function isEnumNode(array $node): bool {
    return !empty($node['styles']) || !empty($node['enum']);
  }

  /**
   * Whether a shape is barred from contributing text whatever it declares.
   */
  public function isBarred(string $shapeId): bool {
    if ($shapeId === '') {
      return TRUE;
    }
    // An explicit FALSE bars the subtree, not just the value. A shape says this
    // when it has children worth rendering that say nothing about the entity —
    // left to recurse, its string and link children would each answer, quite
    // correctly for themselves, that they are text.
    if ($this->definitionValue($shapeId, 'text_keys') === FALSE) {
      return TRUE;
    }
    foreach (self::BARRED_INTERFACES as $interface) {
      if ($this->implementsInterface($shapeId, $interface)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The text a shape declares its value carries.
   *
   * @return true|string[]|null
   *   TRUE when the whole value is text, an allow-list of value keys when only
   *   part of it is, or NULL when the shape declares none.
   */
  public function textKeys(string $shapeId): true|array|null {
    if ($this->isBarred($shapeId)) {
      return NULL;
    }
    $keys = $this->definitionValue($shapeId, 'text_keys');
    if ($keys === TRUE) {
      return TRUE;
    }
    return is_array($keys) && $keys !== [] ? array_values($keys) : NULL;
  }

  /**
   * Whether a shape's text carries HTML that must be stripped.
   */
  public function isMarkup(string $shapeId): bool {
    return $this->definitionValue($shapeId, 'text_markup') === TRUE;
  }

  /**
   * Whether a shape holds children rather than a value of its own.
   *
   * Asked of the shape's own structure marker rather than a list of ids, which
   * also catches the composite shapes that are not object-derived — a link is
   * one — and any container a contrib module adds.
   */
  public function isContainer(string $shapeId): bool {
    return !$this->isBarred($shapeId)
      && $this->implementsInterface($shapeId, ComponentShapeChildrenMatchPluginInterface::class);
  }

  /**
   * Whether a container's children are delta-keyed rather than named.
   */
  public function isIterable(string $shapeId): bool {
    return $this->implementsInterface($shapeId, ComponentShapeInterablePluginInterface::class);
  }

  /**
   * Reads one property off a shape's cached definition.
   */
  private function definitionValue(string $shapeId, string $key): mixed {
    if ($shapeId === '') {
      return NULL;
    }
    $definition = $this->shapeManager->getDefinition($shapeId, FALSE);
    return is_array($definition) ? ($definition[$key] ?? NULL) : NULL;
  }

  /**
   * Whether a shape's class implements an interface, memoised.
   */
  private function implementsInterface(string $shapeId, string $interface): bool {
    if (isset($this->implements[$shapeId][$interface])) {
      return $this->implements[$shapeId][$interface];
    }
    $definition = $this->shapeManager->getDefinition($shapeId, FALSE);
    $class = is_array($definition) ? ($definition['class'] ?? NULL) : NULL;
    $result = is_string($class) && is_a($class, $interface, TRUE);
    return $this->implements[$shapeId][$interface] = $result;
  }

}
