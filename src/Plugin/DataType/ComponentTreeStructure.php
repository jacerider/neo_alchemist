<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

use Drupal\Component\Graph\Graph;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * The component tree structure's data structure is optimized for efficiency.
 *
 * - The component tree is represented as an array of component subtrees.
 * - Each component subtree is keyed by its parent component instance's UUID.
 * - There is one special case: the root, which has a reserved UUID.
 * - Each component subtree contains only its children, not grandchildren — its
 *   depth is hence always 1.
 * - Each component subtree contains a list of populated slot names, with an
 *   ordered list of component "uuid,component" tuples in each populated slot.
 *   The sole exception is the root, which contains has no slot names: it is
 *   essentially a slot.
 * - Hence each component subtree contains only its children, not grandchildren;
 *   its depth is hence always 1.
 *
 * This avoids the need for deep tree traversal: the depth of the data structure
 * when represented as PHP arrays is at most 4 levels:
 * - the top level lists the root UUID plus all component instances that contain
 *   subtrees
 * - the root component subtree contains "uuid,component" tuples, bringing it to
 *   3 levels deep: level 2 contains the tuples, level 3 is each tuple
 *   represented as an array
 * - the other component subtrees contain populated slot names, followed by the
 *   aforementioned tuples, bringing it to 4 levels deep: level 2 contains the
 *   populated slot names, level 3 contains the tuples in each populated slot,
 *   and level 4 is each tuple represented as an array
 *
 * The costly consequence is that the complete component tree is not readily
 * available: it requires some assembly. However, since this requires rendering
 * anyway, this cost is negligible.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated
 *
 * The benefits:
 * - finding a component instance by UUID or by component does not require tree
 *   traversal; it can happen more efficiently
 * - less recursion throughout the codebase — this tree is the heart of
 *   Experience Builder, and how it works affects the entire codebase
 * - … for example in the validation logic
 * - updating/migrating existing component instances is hence simpler
 * - bugs in update/migration paths cannot easily corrupt the entire tree
 *
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 * @see \Drupal\Tests\neo_alchemist\Kernel\DataType\ComponentTreeStructureTest
 */
#[DataType(
  id: "neo_component_tree_structure",
  label: new TranslatableMarkup("Component tree structure"),
  description: new TranslatableMarkup("The structure of the component tree: without props values"),
  constraints: [
    "ComponentTreeStructure" => [],
  ]
)]
class ComponentTreeStructure extends TypedData {

  const ROOT_UUID = 'a548b48d-jac3-r1d3r-aa04-da9405a6f418';

  /**
   * The data value.
   *
   * @var string
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @var array
   *   The component tree structure.
   */
  protected array $tree = [];

  /**
   * The graph.
   *
   * @var null|array
   *   The graph representation of the component tree.
   */
  protected ?array $graph = NULL;

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE): self {
    // Default to a JSON object with only the root key present.
    $this->setValue('{"' . self::ROOT_UUID . '": []}', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
    $this->value = $value;
    $this->tree = Json::decode($value);

    // Keep the graph representation in sync: force it to be recomputed.
    $this->graph = NULL;

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return Json::encode($this->tree);
  }

  /**
   * Retrieves an array of UUIDs for the component instances.
   *
   * @return array
   *   An array of UUIDs for the component instances.
   */
  public function getComponents(): array {
    $components = [];
    foreach ($this->tree as $uuid => $sub_tree_value) {
      $components = array_merge($components, $this->getComponentsBySection($uuid));
    }
    return $components;
  }

  /**
   * Get component instance UUIDs.
   *
   * @return array
   *   An array of component instance UUIDs.
   */
  public function getComponentInstanceUuids(?string $parentUuid = NULL, ?string $slot = NULL): array {
    $components = $parentUuid ? $this->getComponentsBySection($parentUuid, $slot) : $this->getComponents();
    return array_column($components, 'uuid');
  }

  /**
   * Retrieves the components for a given section.
   *
   * This method returns an array of components associated with a specified
   * parent UUID. If the parent UUID is the root UUID, it merges the components
   * directly from the tree. Otherwise, it iterates through the sections and
   * extracts UUID values from each inner array.
   *
   * @param string $parentUuid
   *   The UUID of the parent section.
   * @param string|null $slot
   *   The slot name.
   *
   * @return array
   *   An array of components associated with the specified parent UUID.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the items in a section are not an array.
   */
  public function getComponentsBySection(string $parentUuid = self::ROOT_UUID, ?string $slot = NULL): array {
    $components = [];
    if (isset($this->tree[$parentUuid])) {
      if ($parentUuid === self::ROOT_UUID) {
        $components = array_merge($components, $this->tree[$parentUuid]);
      }
      else {
        if ($slot) {
          if (!isset($this->tree[$parentUuid][$slot])) {
            throw new \UnexpectedValueException(sprintf('Expected a slot named %s in %s, but it does not exist.', $slot, $parentUuid));
          }
          if (!is_array($this->tree[$parentUuid][$slot])) {
            // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
            throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $slot, gettype($this->tree[$parentUuid][$slot])));
          }
          $components = array_merge($components, $this->tree[$parentUuid][$slot]);
        }
        else {
          foreach ($this->tree[$parentUuid] as $slot => $items) {
            if (!is_array($items)) {
              // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
              throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $slot, gettype($items)));
            }
            // Efficiently extract UUID values from each inner array.
            $components = array_merge($components, $items);
          }
        }
      }
    }
    return $components;
  }

  /**
   * Sorts components based on the provided UUIDs.
   *
   * @param array $uuids
   *   An array of component UUIDs to sort.
   * @param string $parentUuid
   *   The UUID of the parent component. Defaults to the root UUID.
   * @param string|null $slot
   *   The slot within the parent component. Required if the parent is not the
   *   root.
   *
   * @throws \InvalidArgumentException
   *   Thrown when sorting on a non-root parent without specifying a slot.
   */
  public function sortComponents(array $uuids, string $parentUuid = self::ROOT_UUID, ?string $slot = NULL) {
    if ($parentUuid !== self::ROOT_UUID && $slot === NULL) {
      throw new \InvalidArgumentException('When sorting on a non-root parent, a slot is required.');
    }
    $components = $this->getComponentsBySection($parentUuid, $slot);
    $sorted = [];
    foreach ($uuids as $uuid) {
      foreach ($components as $component) {
        if ($component['uuid'] === $uuid) {
          $sorted[] = $component;
          break;
        }
      }
    }
    if ($slot) {
      $this->tree[$parentUuid][$slot] = $sorted;
    }
    else {
      $this->tree[self::ROOT_UUID] = $sorted;
    }
    $this->setValue(Json::encode($this->tree));
  }

  /**
   * Add a component instance to the tree.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   * @param string $neoComponentId
   *   The ID of the component.
   * @param string $parentUuid
   *   The UUID of the parent component instance.
   * @param string|null $slot
   *   The slot name.
   */
  public function addComponent(string $uuid, string $neoComponentId, string $parentUuid = self::ROOT_UUID, ?string $slot = NULL) {
    if ($parentUuid === self::ROOT_UUID) {
      $tree = &$this->tree[$parentUuid];
    }
    else {
      assert($slot !== NULL, 'A slot is required when adding a component to a non-root parent.');
      $tree = &$this->tree[$parentUuid][$slot];
    }
    // Remove from tree if it already exists.
    $tree = array_filter($tree ?? [], fn($v) => $v['uuid'] !== $uuid);
    // Add to tree.
    $tree[] = [
      'uuid' => $uuid,
      'component' => $neoComponentId,
    ];
    $this->setValue(Json::encode($this->tree));
  }

  /**
   * Removes a component from the tree based on the provided UUID.
   *
   * @param string $uuid
   *   The UUID of the component to be removed.
   */
  public function removeComponent(string $uuid) {
    foreach ($this->tree as $sub_tree_uuid => &$sub_tree_value) {
      if ($sub_tree_uuid === self::ROOT_UUID) {
        $sub_tree_value = array_filter($sub_tree_value ?? [], fn($v) => $v['uuid'] !== $uuid);
        continue;
      }
      foreach ($sub_tree_value as $slot => $items) {
        assert(is_array($items));
        $sub_tree_value[$slot] = array_filter($items, fn($v) => $v['uuid'] !== $uuid);
      }
    }
    unset($this->tree[$uuid]);
    $this->setValue(Json::encode($this->tree));
  }

  /**
   * Get component ID.
   *
   * @param string $uuid
   *   The UUID of a placed component instance.
   *
   * @return ComponentConfigEntityId
   *   A Component config entity ID.
   *
   * @see \Drupal\experience_builder\Entity\Component
   */
  public function getComponentId(string $uuid): ?string {
    if (!in_array($uuid, $this->getComponentInstanceUuids(), TRUE)) {
      return NULL;
    }
    $components = $this->getComponents();
    $index = array_search($uuid, array_column($components, 'uuid'));
    return $components[$index]['component'];
  }

  /**
   * Get component parent UUID.
   *
   * @param string $uuid
   *   The UUID of a placed component instance.
   *
   * @return string|null
   *   The parent UUID, or NULL if the component is not found or is the root.
   */
  public function getComponentParentUuid(string $uuid): ?string {
    if (!in_array($uuid, $this->getComponentInstanceUuids(), TRUE)) {
      return NULL;
    }
    if ($uuid === self::ROOT_UUID) {
      return NULL;
    }
    foreach ($this->tree as $parent_uuid => $sub_tree_value) {
      foreach ($sub_tree_value as $slot => $items) {
        if ($parent_uuid === self::ROOT_UUID) {
          continue;
        }
        foreach ($items as $component) {
          if ($component['uuid'] === $uuid) {
            return $parent_uuid;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Get component slot.
   *
   * @param string $uuid
   *   The UUID of a placed component instance.
   *
   * @return string|null
   *   The slot name, or NULL if the component is not found or is the root.
   */
  public function getComponentSlot(string $uuid): ?string {
    if (!in_array($uuid, $this->getComponentInstanceUuids(), TRUE)) {
      return NULL;
    }
    if ($uuid === self::ROOT_UUID) {
      return NULL;
    }
    foreach ($this->tree as $parent_uuid => $sub_tree_value) {
      foreach ($sub_tree_value as $slot => $items) {
        if ($parent_uuid === self::ROOT_UUID) {
          continue;
        }
        foreach ($items as $component) {
          if ($component['uuid'] === $uuid) {
            return $slot;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Get slot children depth-first.
   *
   * @return \Generator
   *   The slot children depth-first.
   */
  public function getSlotChildrenDepthFirst(): \Generator {
    if ($this->graph === NULL) {
      $this->graph = self::constructDepthFirstGraph($this->tree);
    }
    foreach ($this->graph as $vertex_key => $vertex) {
      // This method is concerned only with component instances in slots. Those
      // are easily identified by their vertex key: they must contain a colon,
      // which separates the parent component instance UUID from the slot name.
      if (!str_contains($vertex_key, ':')) {
        continue;
      }
      [$parent_uuid, $slot] = explode(':', $vertex_key, 2);

      // For each vertex (after the filtering above), all edges represent
      // child component instances placed in this slot.
      foreach (array_keys($vertex['edges']) as $uuid) {
        assert(is_string($uuid));
        yield $parent_uuid => [
          'slot' => $slot,
          'uuid' => $uuid,
        ];
      }
    }
  }

  /**
   * Constructs a depth-first graph based on the given tree.
   *
   * @param array $tree
   *   The tree.
   *
   * @return array
   *   The depth-first graph.
   *
   * @see \Drupal\Component\Graph\Graph
   */
  private static function constructDepthFirstGraph(array $tree): array {
    // Transform the tree to the input expected by Drupal's Graph utility.
    $graph = [];
    foreach ($tree as $component_subtree_uuid => $value) {
      if ($component_subtree_uuid === self::ROOT_UUID) {
        foreach (array_column($value, 'uuid') as $uuid) {
          assert(is_string($uuid));
          $graph[$component_subtree_uuid]['edges'][$uuid] = TRUE;
        }
        continue;
      }

      foreach ($value as $slot => $component_instances) {
        $graph[$component_subtree_uuid]['edges']["$component_subtree_uuid:$slot"] = TRUE;
        foreach (array_column($component_instances, 'uuid') as $uuid) {
          $graph["$component_subtree_uuid:$slot"]['edges'][$uuid] = TRUE;
        }
      }
    }

    // Use Drupal's battle-hardened Graph utility.
    $sorted_graph = (new Graph($graph))->searchAndSort();

    // Sort by weight, then reverse: this results in a depth-first sorted graph.
    uasort($sorted_graph, [SortArray::class, 'sortByWeightElement']);
    $reverse_sorted_graph = array_reverse($sorted_graph);

    return $reverse_sorted_graph;
  }

}
