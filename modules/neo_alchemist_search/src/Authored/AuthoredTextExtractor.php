<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Authored;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist_search\ComponentTextBuffer;
use Psr\Log\LoggerInterface;

/**
 * Extracts the text an editor typed into an entity's own components.
 *
 * This walks the stored prop values against the component's schema. It loads
 * no entities, instantiates no shapes, executes no views and renders nothing —
 * which is the whole point, since the alternative it replaces rendered every
 * entity through the full view pipeline at index time.
 *
 * What it deliberately does not cover is props fed by value providers. Those
 * store nothing in the row; their text lives on the host entity's ordinary
 * fields and is recovered by the binding half. For a locked field this class
 * correctly returns nothing at all.
 *
 * @see \Drupal\neo_alchemist_search\Authored\OwnershipGate
 * @see \Drupal\neo_alchemist_search\Binding\BindingTextReader
 */
final class AuthoredTextExtractor {

  /**
   * Guards against a schema that nests pathologically or refers to itself.
   */
  private const MAX_DEPTH = 8;

  /**
   * Array-item keys the widget adds that are never content.
   */
  private const ITEM_METADATA_KEYS = [
    '_weight' => TRUE,
    '_options' => TRUE,
  ];

  /**
   * Component ids already reported missing during this request.
   *
   * @var array<string, true>
   */
  private array $reportedMissing = [];

  /**
   * Constructs an AuthoredTextExtractor.
   *
   * @param \Drupal\neo_alchemist_search\Authored\OwnershipGate $gate
   *   Decides which instances belong to the entity.
   * @param \Drupal\neo_alchemist_search\Authored\ShapeTextPolicy $policy
   *   Decides which shapes and keys carry text.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the neo_component config entities behind the placed instances.
   * @param \Psr\Log\LoggerInterface $logger
   *   Reports placements whose component has gone away.
   */
  public function __construct(
    private readonly OwnershipGate $gate,
    private readonly ShapeTextPolicy $policy,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Collects the entity's own authored text from one component tree field.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The host entity, in the language being indexed.
   * @param string $fieldName
   *   The name of a `neo_component_tree` field on that entity.
   * @param \Drupal\neo_alchemist_search\ComponentTextBuffer $buffer
   *   Collects the text runs.
   */
  public function extract(ContentEntityInterface $entity, string $fieldName, ComponentTextBuffer $buffer): void {
    [$item, $owned] = $this->gate->open($entity, $fieldName);
    if ($item === NULL) {
      return;
    }

    $tree = $item->get('tree');
    $props = $item->get('props');
    if (!$tree instanceof ComponentTreeStructure || !$props instanceof ComponentPropsValues) {
      return;
    }

    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      if ($buffer->isFull()) {
        return;
      }
      // Inherited from the field default layout: shared by every entity of the
      // bundle, so indexing it would say nothing about this one.
      if ($owned !== NULL && !isset($owned[$uuid])) {
        continue;
      }
      if (!$props->hasComponent($uuid)) {
        // Tree and props disagree. preSave() rejects this, but a row written
        // before that guard existed can still be sitting in storage.
        continue;
      }
      $record = $props->getComponentPropsSources($uuid);
      if (!is_array($record) || !is_array($record['props'] ?? NULL)) {
        continue;
      }
      // An unpublished instance renders nothing, and neither do its children.
      // getComponentInstanceUuids() is flat, so a descendant of an unpublished
      // parent is reached independently — but hiding a parent to hide a branch
      // is a rare authoring move, and over-indexing a child is far less costly
      // than skipping a whole published branch by mistake.
      if (array_key_exists('status', $record) && !$record['status']) {
        continue;
      }

      $component = $this->loadComponent($tree->getComponentId($uuid));
      if ($component === NULL) {
        continue;
      }
      $schema = $this->schemaFor($component);
      if ($schema === []) {
        continue;
      }

      foreach ($record['props'] as $propName => $prop) {
        if (!is_array($prop)) {
          // Seen in real rows: a prop stored as [] rather than a value wrapper.
          continue;
        }
        $node = $this->schemaNode($schema, (string) $propName);
        if ($node === NULL) {
          continue;
        }
        $options = is_array($prop['options'] ?? NULL) ? $prop['options'] : [];
        $ref = $prop['ref'] ?? $prop['shape'] ?? NULL;
        $this->walk(
          $node,
          $prop['value'] ?? NULL,
          $options,
          [(string) $propName],
          [(string) $propName],
          0,
          $buffer,
          is_string($ref) ? $ref : NULL,
        );
      }
    }
  }

  /**
   * Walks one schema node against its stored value.
   *
   * @param array $node
   *   The schema node describing this value.
   * @param mixed $value
   *   The stored value.
   * @param array $options
   *   The prop's option map, keyed by nested shape id.
   * @param string[] $deltaPath
   *   Path components including array deltas, for option lookup.
   * @param string[] $namePath
   *   Path components without deltas, for option lookup.
   * @param int $depth
   *   Current recursion depth.
   * @param \Drupal\neo_alchemist_search\ComponentTextBuffer $buffer
   *   Collects the text runs.
   * @param string|null $storedRef
   *   The `ref` stored beside the value, at the top level only.
   */
  private function walk(
    array $node,
    mixed $value,
    array $options,
    array $deltaPath,
    array $namePath,
    int $depth,
    ComponentTextBuffer $buffer,
    ?string $storedRef = NULL,
  ): void {
    if ($depth > self::MAX_DEPTH || $buffer->isFull()) {
      return;
    }
    if ($value === NULL || $value === '' || $value === []) {
      return;
    }
    // `empty` short-circuits before the value is ever built, and the option
    // policy locks it onto every child — so it prunes the whole subtree.
    if ($this->optionIsSet($options, $deltaPath, $namePath, 'empty')) {
      return;
    }
    // `default` discards the stored override in favour of a provider or
    // fallback, which makes the stored value dead text that never renders.
    if ($this->optionIsSet($options, $deltaPath, $namePath, 'default')) {
      return;
    }
    if ($this->policy->isEnumNode($node)) {
      return;
    }

    $shapeId = $this->policy->shapeId($node, $storedRef);
    if ($this->policy->isBarred($shapeId)) {
      return;
    }

    // A container whose children are delta-keyed rather than named.
    if ($this->policy->isIterable($shapeId)) {
      $this->walkArray($node, $value, $options, $deltaPath, $namePath, $depth, $buffer);
      return;
    }

    // Containers are resolved before the text declaration, because holding
    // children is not the same as holding text: an object declares no text of
    // its own and still has to be descended into.
    $textKeys = $this->policy->textKeys($shapeId);
    if ($this->policy->isContainer($shapeId)) {
      if (!is_array($value)) {
        return;
      }
      // Declared keys on a container name the children worth reading; none
      // declared means every child is fair game.
      $allowed = is_array($textKeys) ? array_flip($textKeys) : NULL;
      foreach (($node['properties'] ?? []) as $childName => $childNode) {
        if (!is_array($childNode) || !array_key_exists($childName, $value)) {
          continue;
        }
        if ($allowed !== NULL && !isset($allowed[$childName])) {
          continue;
        }
        $this->walk(
          $childNode,
          $value[$childName],
          $options,
          [...$deltaPath, (string) $childName],
          [...$namePath, (string) $childName],
          $depth + 1,
          $buffer,
        );
      }
      return;
    }

    if ($textKeys === NULL) {
      return;
    }

    // A leaf that is not a container but still names keys: an address, whose
    // composite value has no child shapes to recurse into.
    if (is_array($textKeys)) {
      if (!is_array($value)) {
        return;
      }
      foreach ($textKeys as $key) {
        $buffer->add($value[$key] ?? NULL, $this->policy->isMarkup($shapeId));
      }
      return;
    }

    // A scalar leaf. Single-property field items are stored wrapped under their
    // main property name and unwrapped again at render time.
    $buffer->add(
      is_array($value) ? ($value['value'] ?? NULL) : $value,
      $this->policy->isMarkup($shapeId),
    );
  }

  /**
   * Walks the deltas of an array shape.
   *
   * @param array $node
   *   The array schema node.
   * @param mixed $value
   *   The stored list.
   * @param array $options
   *   The prop's option map.
   * @param string[] $deltaPath
   *   Path components including deltas.
   * @param string[] $namePath
   *   Path components without deltas.
   * @param int $depth
   *   Current recursion depth.
   * @param \Drupal\neo_alchemist_search\ComponentTextBuffer $buffer
   *   Collects the text runs.
   */
  private function walkArray(
    array $node,
    mixed $value,
    array $options,
    array $deltaPath,
    array $namePath,
    int $depth,
    ComponentTextBuffer $buffer,
  ): void {
    if (!is_array($value)) {
      return;
    }
    $items = is_array($node['items'] ?? NULL) ? $node['items'] : [];
    $properties = is_array($items['properties'] ?? NULL) ? $items['properties'] : [];

    foreach ($value as $delta => $row) {
      if (!is_int($delta) || $buffer->isFull()) {
        continue;
      }
      if ($properties === []) {
        // A list of scalars: the row is the value itself.
        $this->walk($items, $row, $options, [...$deltaPath, (string) $delta], $namePath, $depth + 1, $buffer);
        continue;
      }
      if (!is_array($row)) {
        continue;
      }
      foreach ($properties as $childName => $childNode) {
        if (isset(self::ITEM_METADATA_KEYS[$childName])) {
          continue;
        }
        if (!is_array($childNode) || !array_key_exists($childName, $row)) {
          continue;
        }
        $this->walk(
          $childNode,
          $row[$childName],
          $options,
          // The option map keys a child by name then delta, not the reverse.
          [...$deltaPath, (string) $childName, (string) $delta],
          [...$namePath, (string) $childName],
          $depth + 1,
          $buffer,
        );
      }
    }
  }

  /**
   * Whether an option flag is set for the node at either of its two keyings.
   *
   * Stored option maps carry both instance-scope keys, which include array
   * deltas, and config-scope keys, which do not — the two are merged on load
   * and written back together. A flag set under either keying applies.
   *
   * @param array $options
   *   The prop's option map.
   * @param string[] $deltaPath
   *   Path components including deltas.
   * @param string[] $namePath
   *   Path components without deltas.
   * @param string $flag
   *   The option name to test.
   *
   * @return bool
   *   TRUE when the flag is set.
   */
  private function optionIsSet(array $options, array $deltaPath, array $namePath, string $flag): bool {
    foreach ([implode('~', $deltaPath), implode('~', $namePath)] as $key) {
      $record = $options[$key] ?? NULL;
      // Option records are sometimes stored as a list rather than a map; those
      // carry no flags. Values arrive as both 0/1 and FALSE/TRUE.
      if (is_array($record) && !empty($record[$flag])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads the component config entity behind a placement.
   *
   * @param string|null $componentId
   *   The component id recorded in the tree.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface|null
   *   The component, or NULL when it is missing or disabled.
   */
  private function loadComponent(?string $componentId): ?ComponentInterface {
    if ($componentId === NULL || $componentId === '') {
      return NULL;
    }
    $component = $this->entityTypeManager->getStorage('neo_component')->load($componentId);
    if (!$component instanceof ComponentInterface) {
      if (!isset($this->reportedMissing[$componentId])) {
        $this->reportedMissing[$componentId] = TRUE;
        $this->logger->warning('Skipped indexing a placement of the missing component %component.', [
          '%component' => $componentId,
        ]);
      }
      return NULL;
    }
    // A disabled component renders nothing, so it says nothing about the page.
    return $component->status() ? $component : NULL;
  }

  /**
   * Resolves a component's prop schema.
   *
   * Prefers the live single-directory component, which is always current, and
   * falls back to the snapshot the config entity stores so a component whose
   * source has been removed still yields its authored text.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component config entity.
   *
   * @return array
   *   The `properties` map of the prop schema, or an empty array.
   */
  private function schemaFor(ComponentInterface $component): array {
    $schema = $component->getComponentSchema();
    if (!is_array($schema) || !is_array($schema['properties'] ?? NULL)) {
      $stored = $component->get('schema');
      $schema = is_string($stored) ? Json::decode($stored) : NULL;
    }
    return is_array($schema) && is_array($schema['properties'] ?? NULL) ? $schema : [];
  }

  /**
   * Finds the schema node for a prop, unwrapping aggregate components.
   *
   * @param array $schema
   *   The component's prop schema.
   * @param string $propName
   *   The stored prop name.
   *
   * @return array|null
   *   The schema node, or NULL when the schema has drifted away from storage.
   */
  private function schemaNode(array $schema, string $propName): ?array {
    $properties = $schema['properties'] ?? [];
    if (is_array($properties[$propName] ?? NULL)) {
      return $properties[$propName];
    }
    // An aggregate component stores its props under a single wrapper key that
    // the schema does not name.
    if ($propName === '_aggregate') {
      return ['type' => 'object', 'ref' => 'object', 'properties' => $properties];
    }
    return NULL;
  }

}
