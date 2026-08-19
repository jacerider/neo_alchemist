<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

use Drupal\Component\Graph\Graph;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\neo_alchemist\EmptySectionPolicy;

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
 * ## This class is the seam for the decoded component tree
 *
 * Component usage scanning, config dependency detachment, hybrid merge and
 * strip, custom-region anchor resolution, structure validation and the Drush
 * integrity command all operate on the decoded `['tree' => …, 'props' => …]`
 * pair. Each of them used to re-derive the traversal it needed, so the copies
 * were free to disagree about the invariants — and they did. Everything that
 * knows the *shape* of a decoded tree lives here now, as static algebra any
 * caller can reach without a container:
 * - ::expandClosure() is the one descendant walker.
 * - ::collectUuids() / ::collectInstanceUuids() / ::collectInstances() /
 *   ::collectComponentIds() are the collectors, all built on one section walk.
 * - ::detachComponents() removes every instance of a set of components.
 * - ::composeHybrid() / ::extractHybridStorage() are the hybrid algebra.
 *
 * Instance operations additionally own the **pair**: bind the props companion
 * with ::bindProps() and every operation that removes or relocates an instance
 * maintains tree↔props parity as a postcondition rather than as a rule each
 * caller has to remember. Unbound, the class behaves exactly as it always did.
 *
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 * @see \Drupal\Tests\neo_alchemist\Unit\ComponentTreeStructureTest
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
   * Placement of every component instance, keyed by UUID.
   *
   * Each entry holds the component ID plus where the instance sits:
   * ['component' => id, 'parent' => ?uuid, 'slot' => ?slot], with parent and
   * slot NULL for root-level instances. Built in a single pass and dropped
   * alongside the graph whenever the value changes.
   *
   * @var null|array
   */
  protected ?array $index = NULL;

  /**
   * The props companion this tree is paired with, when bound.
   *
   * @var \Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues|null
   *
   * @see self::bindProps()
   */
  protected ?ComponentPropsValues $props = NULL;

  /**
   * Binds the props companion, making this object own the (tree, props) pair.
   *
   * Every operation that removes an instance then maintains parity — no props
   * entry outlives its instance, and every instance keeps an entry — as a
   * postcondition. Without a binding the tree is written on its own exactly as
   * before, which is what config-scope and read-only callers want.
   *
   * @param \Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues $props
   *   The props values for the same field item.
   *
   * @return $this
   */
  public function bindProps(ComponentPropsValues $props): static {
    $this->props = $props;
    return $this;
  }

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

    // Keep the derived representations in sync: force them to be recomputed.
    $this->graph = NULL;
    $this->index = NULL;

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
   * The decoded tree this object currently holds.
   *
   * @return array
   *   The decoded component tree.
   */
  public function getTree(): array {
    return $this->tree;
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
          foreach ($this->tree[$parentUuid] as $sectionSlot => $items) {
            if (!is_array($items)) {
              // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
              throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $sectionSlot, gettype($items)));
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
   * Reorders a section, permuting only the UUIDs it is given.
   *
   * This is deliberately NOT a rebuild. The destructive predecessor
   * (`sortComponents()`) reassembled the section from the supplied list and
   * discarded everything absent from it, which turned a presentation-layer
   * decision into a data change: the helper that maps a section to labelled
   * options skips any instance whose `neo_component` config no longer loads,
   * so reordering a section holding a broken component silently deleted it,
   * stranding its subtree and props in exactly the dangling state the
   * structure validator rejects.
   *
   * Instead, the positions occupied by the listed UUIDs are collected and
   * refilled in the requested order. Anything the caller did not mention keeps
   * its own index, so a reorder is a permutation and can never be a deletion —
   * regardless of what the caller passes.
   *
   * @param array $uuids
   *   The component instance UUIDs to permute, in their new relative order.
   *   UUIDs not placed in this section are ignored.
   * @param string $parentUuid
   *   The UUID of the parent component. Defaults to the root UUID.
   * @param string|null $slot
   *   The slot within the parent component. Required if the parent is not the
   *   root.
   *
   * @throws \InvalidArgumentException
   *   Thrown when reordering a non-root parent without specifying a slot.
   */
  public function reorderComponents(array $uuids, string $parentUuid = self::ROOT_UUID, ?string $slot = NULL): void {
    if ($parentUuid !== self::ROOT_UUID && $slot === NULL) {
      throw new \InvalidArgumentException('When reordering a non-root parent, a slot is required.');
    }
    $components = array_values($this->getComponentsBySection($parentUuid, $slot));
    $placed = array_column($components, 'uuid');
    // A UUID listed twice would otherwise be written into two positions and
    // evict whatever sat in the second one, which is the deletion this
    // operation exists to make impossible. First occurrence wins.
    $uuids = array_values(array_unique($uuids));

    // The positions to refill, in section order, and the tuples to put in
    // them, in the requested order. Both lists are built from the section
    // itself, so a UUID the caller invented contributes nothing and a UUID the
    // caller forgot is simply never moved. They are the same length by
    // construction: a UUID contributes a position exactly when it is placed,
    // and contributes a tuple under the same condition.
    $positions = [];
    $moving = [];
    foreach ($placed as $index => $uuid) {
      if (in_array($uuid, $uuids, TRUE)) {
        $positions[] = $index;
      }
    }
    foreach ($uuids as $uuid) {
      $index = array_search($uuid, $placed, TRUE);
      if ($index !== FALSE) {
        $moving[] = $components[$index];
      }
    }
    assert(count($positions) === count($moving));
    foreach ($positions as $offset => $index) {
      $components[$index] = $moving[$offset];
    }

    if ($slot) {
      $this->tree[$parentUuid][$slot] = $components;
    }
    else {
      $this->tree[self::ROOT_UUID] = $components;
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
   * @param array|null $propValues
   *   The prop values for the instance. Only meaningful when a props companion
   *   is bound: NULL leaves an existing entry alone and creates an empty one
   *   where none exists, so parity holds either way.
   */
  public function addComponent(string $uuid, string $neoComponentId, string $parentUuid = self::ROOT_UUID, ?string $slot = NULL, ?array $propValues = NULL) {
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

    // Parity: an instance without a props entry is exactly what the save-time
    // check rejects, so create one here rather than leaving it to the caller.
    // An add doubles as a move — the UUID is dropped from its section before
    // being appended — so NULL values must not blank what the instance had.
    if ($this->props !== NULL && ($propValues !== NULL || !$this->props->hasComponent($uuid))) {
      $this->props->setComponent($uuid, $propValues ?? []);
    }
  }

  /**
   * Removes a component from the tree based on the provided UUID.
   *
   * Removing a component removes everything underneath it. Dropping only its
   * own section leaves each descendant's section in place but unreachable — a
   * dangling subtree, which the structure validator rejects and the hydrated
   * tree silently discards at render.
   *
   * @param string $uuid
   *   The UUID of the component to be removed.
   * @param \Drupal\neo_alchemist\EmptySectionPolicy $policy
   *   What to do with a slot or section the removal empties. Deliberately not
   *   defaulted: the two readings are individually correct and diverge
   *   silently.
   *
   * @see \Drupal\neo_alchemist\EmptySectionPolicy
   */
  public function removeComponent(string $uuid, EmptySectionPolicy $policy) {
    $removed = self::expandClosure($this->tree, [$uuid]);
    $this->tree = self::pruneTree($this->tree, array_fill_keys($removed, TRUE), $policy);
    $this->setValue(Json::encode($this->tree));

    // Parity: props are keyed by instance UUID with no parent links, so once
    // the sections are gone nothing is left to work out which prop values
    // belonged underneath. Drop them here, while the closure is still known.
    if ($this->props !== NULL) {
      foreach ($removed as $removedUuid) {
        $this->props->removeComponent($removedUuid);
      }
    }
  }

  /**
   * Lists a component instance and every descendant beneath it.
   *
   * @param string $uuid
   *   The UUID of the component instance at the top of the subtree.
   *
   * @return string[]
   *   The UUID passed in, followed by the UUIDs of all its descendants.
   */
  public function getSubtreeUuids(string $uuid): array {
    return self::expandClosure($this->tree, [$uuid]);
  }

  /**
   * Get component ID.
   *
   * @param string $uuid
   *   The UUID of a placed component instance.
   *
   * @return string|null
   *   A Component config entity ID.
   *
   * @see \Drupal\experience_builder\Entity\Component
   */
  public function getComponentId(string $uuid): ?string {
    return $this->getIndex()[$uuid]['component'] ?? NULL;
  }

  /**
   * Builds the UUID-to-placement index, once per value.
   *
   * Each of getComponentId(), getComponentParentUuid() and getComponentSlot()
   * used to guard with a full scan for the UUID and then walk the tree again
   * to answer, so instantiating one component instance walked the whole tree
   * six times — and the parent and slot walks were duplicates of each other
   * that could in principle disagree. One pass answers all three, and parent
   * and slot come from the same record.
   *
   * Where a UUID appears more than once the first placement in traversal order
   * wins, matching what the sequential scans returned.
   *
   * @return array
   *   Placement records keyed by component instance UUID.
   *
   * @throws \UnexpectedValueException
   *   Thrown when a slot's items are not an array, as the section readers do.
   */
  private function getIndex(): array {
    if ($this->index !== NULL) {
      return $this->index;
    }
    $this->index = [];
    foreach ($this->tree as $sectionUuid => $sectionValue) {
      if ($sectionUuid === self::ROOT_UUID) {
        foreach ($sectionValue ?? [] as $component) {
          $this->index[$component['uuid']] ??= [
            'component' => $component['component'] ?? NULL,
            'parent' => NULL,
            'slot' => NULL,
          ];
        }
        continue;
      }
      foreach ($sectionValue as $slot => $items) {
        if (!is_array($items)) {
          // @see self::getComponentsBySection()
          throw new \UnexpectedValueException(sprintf('Expected an array of items expect in %s, but got %s.', $slot, gettype($items)));
        }
        foreach ($items as $component) {
          $this->index[$component['uuid']] ??= [
            'component' => $component['component'] ?? NULL,
            'parent' => $sectionUuid,
            'slot' => $slot,
          ];
        }
      }
    }
    return $this->index;
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
    return $this->getIndex()[$uuid]['parent'] ?? NULL;
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
    return $this->getIndex()[$uuid]['slot'] ?? NULL;
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
      // child component instances placed in this slot. An explicitly emptied
      // slot has none: Graph::depthFirstSearch() mints a vertex for every edge
      // target, so the slot is in the sorted graph with no 'edges' key at all.
      foreach (array_keys($vertex['edges'] ?? []) as $uuid) {
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

  /**
   * Walks every "uuid,component" tuple in a decoded tree.
   *
   * The one section walk. The root section is a bare tuple list while every
   * other section is a map of slot name => tuple list, and that difference was
   * written out verbatim in three places across two classes before this.
   *
   * A section that is a PHP list is treated as root-shaped even when it is not
   * keyed by the root UUID, so a partial or hand-assembled tree still walks
   * rather than silently yielding nothing.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return \Generator
   *   Yields each "uuid,component" tuple.
   */
  private static function walkTuples(array $tree): \Generator {
    foreach ($tree as $key => $section) {
      if (!is_array($section)) {
        continue;
      }
      $isRoot = $key === self::ROOT_UUID || array_is_list($section);
      foreach ($isRoot ? [$section] : $section as $tuples) {
        foreach ((array) $tuples as $tuple) {
          if (is_array($tuple) && (isset($tuple['uuid']) || isset($tuple['component']))) {
            yield $tuple;
          }
        }
      }
    }
  }

  /**
   * Expands a list of UUIDs with all of their tree descendants.
   *
   * The single descendant-closure walker. Walks sections breadth-first; a UUID
   * already seen is never queued again, so a tree that somehow contains a
   * cycle terminates instead of hanging.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $uuids
   *   The seed UUIDs. Non-string seeds are skipped rather than fatal.
   *
   * @return string[]
   *   The seed UUIDs plus every descendant found in the tree.
   */
  public static function expandClosure(array $tree, array $uuids): array {
    $found = [];
    $queue = array_values($uuids);
    while ($queue) {
      $uuid = array_shift($queue);
      if (!is_string($uuid) || isset($found[$uuid])) {
        continue;
      }
      $found[$uuid] = TRUE;
      foreach ((array) ($tree[$uuid] ?? []) as $slotTuples) {
        foreach ((array) $slotTuples as $tuple) {
          if (!empty($tuple['uuid'])) {
            $queue[] = $tuple['uuid'];
          }
        }
      }
    }
    return array_keys($found);
  }

  /**
   * Gets the closure of component UUIDs living inside custom-region anchors.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $anchors
   *   Custom-region anchors, as returned by
   *   \Drupal\neo_alchemist\ComponentFieldConfigInterface::getCustomRegions().
   *
   * @return string[]
   *   All component instance UUIDs placed inside the flagged slots, including
   *   nested descendants. Anchor owners themselves are not included.
   */
  public static function collectAnchorClosure(array $tree, array $anchors): array {
    $queue = [];
    foreach ($anchors as $ownerUuid => $anchor) {
      foreach ($anchor['slots'] ?? [] as $slotId) {
        foreach ((array) ($tree[$ownerUuid][$slotId] ?? []) as $tuple) {
          if (!empty($tuple['uuid'])) {
            $queue[] = $tuple['uuid'];
          }
        }
      }
    }
    return self::expandClosure($tree, $queue);
  }

  /**
   * Gets every UUID referenced by a decoded component tree.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   All section keys and tuple UUIDs, excluding the root key.
   */
  public static function collectUuids(array $tree): array {
    $uuids = [];
    foreach (array_keys($tree) as $key) {
      if ($key !== self::ROOT_UUID) {
        $uuids[$key] = TRUE;
      }
    }
    foreach (self::walkTuples($tree) as $tuple) {
      if (!empty($tuple['uuid'])) {
        $uuids[$tuple['uuid']] = TRUE;
      }
    }
    return array_keys($uuids);
  }

  /**
   * Gets every tuple UUID referenced by a decoded component tree.
   *
   * Unlike ::collectUuids(), section-only keys are excluded: in a hybrid
   * storage subset an anchor owner is a section key without being a component
   * instance of the subset itself.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   The tuple UUIDs.
   */
  public static function collectInstanceUuids(array $tree): array {
    $uuids = [];
    foreach (self::walkTuples($tree) as $tuple) {
      if (!empty($tuple['uuid'])) {
        $uuids[$tuple['uuid']] = TRUE;
      }
    }
    return array_keys($uuids);
  }

  /**
   * Maps every placed instance to the component that renders it.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   Component config entity ids, keyed by instance UUID, in tree order.
   */
  public static function collectInstances(array $tree): array {
    $instances = [];
    foreach (self::walkTuples($tree) as $tuple) {
      if (!empty($tuple['uuid']) && !empty($tuple['component'])) {
        $instances[$tuple['uuid']] = $tuple['component'];
      }
    }
    return $instances;
  }

  /**
   * Collects the neo_component config entity ids used in a component tree.
   *
   * @param array $tree
   *   A component tree structure.
   *
   * @return string[]
   *   The unique component config entity ids.
   */
  public static function collectComponentIds(array $tree): array {
    $componentIds = [];
    foreach (self::walkTuples($tree) as $tuple) {
      if (isset($tuple['component']) && is_string($tuple['component'])) {
        $componentIds[$tuple['component']] = $tuple['component'];
      }
    }
    return array_values($componentIds);
  }

  /**
   * Lists a section's children, slot by slot.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param string $uuid
   *   The UUID whose section is being read.
   *
   * @return array
   *   Tuple lists keyed by slot name. Empty when the UUID owns no section.
   */
  public static function collectChildTuples(array $tree, string $uuid): array {
    $children = [];
    foreach ((array) ($tree[$uuid] ?? []) as $slot => $tuples) {
      $children[(string) $slot] = array_values(array_filter(
        (array) $tuples,
        fn ($tuple) => is_array($tuple) && !empty($tuple['uuid']),
      ));
    }
    return $children;
  }

  /**
   * Decodes a field item value into tree and props arrays.
   *
   * The field item holds JSON, but in-memory values are arrays, and both reach
   * the algebra depending on whether the entity was just loaded or just built.
   * Malformed payloads decode to empty arrays, never NULL: callers index
   * straight into the result, so a NULL would become a TypeError somewhere far
   * away from the actual cause.
   *
   * @param array $itemValue
   *   An item value with 'tree'/'props' keys (JSON strings or arrays).
   *
   * @return array
   *   A tuple of the decoded tree and props arrays.
   */
  public static function decodeValue(array $itemValue): array {
    $tree = $itemValue['tree'] ?? NULL;
    $props = $itemValue['props'] ?? NULL;
    return [
      is_string($tree) ? (Json::decode($tree) ?: []) : (is_array($tree) ? $tree : []),
      is_string($props) ? (Json::decode($props) ?: []) : (is_array($props) ? $props : []),
    ];
  }

  /**
   * Whether a decoded tree is a hybrid storage subset rather than a full tree.
   *
   * The discriminant is the root section. A stored hybrid subset always
   * carries an empty one — the anchor owners live in the field default layout,
   * never in the subset — while an in-session merged tree carries the default
   * layout's own root-level instances. This is a statement about tree shape,
   * which is why it lives here rather than on the field list that branches on
   * it.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return bool
   *   TRUE when the tree is shaped like a stored subset.
   */
  public static function isStorageSubset(array $tree): bool {
    return empty($tree[self::ROOT_UUID]);
  }

  /**
   * Removes every instance of the given components from a tree/props pair.
   *
   * Used when a component is deleted, so its hosts are *updated* rather than
   * deleted by the config dependency system. The result must still satisfy
   * ComponentTreeStructureConstraintValidator, which means:
   * - the root uuid key stays even when it ends up empty;
   * - no subtree may be keyed by an instance that no longer exists, so a
   *   removed instance takes its whole descendant subtree with it;
   * - under EmptySectionPolicy::Collapse, slots left with no instances are
   *   omitted and so are subtrees left with no populated slot.
   *
   * @param array $values
   *   The component values, with 'tree' and optionally 'props' keys.
   * @param string[] $componentIds
   *   The component config entity ids to remove.
   * @param \Drupal\neo_alchemist\EmptySectionPolicy $policy
   *   What to do with a slot or section the removal empties.
   *
   * @return array
   *   The updated component values.
   *
   * @see \Drupal\neo_alchemist\EmptySectionPolicy
   */
  public static function detachComponents(array $values, array $componentIds, EmptySectionPolicy $policy): array {
    $tree = $values['tree'] ?? [];
    if (!$tree || !$componentIds) {
      return $values;
    }
    $remove = array_flip($componentIds);

    // Every instance uuid rendered by one of the removed components.
    $doomed = [];
    foreach (self::walkTuples($tree) as $tuple) {
      if (isset($tuple['component'], $tuple['uuid']) && isset($remove[$tuple['component']])) {
        $doomed[] = $tuple['uuid'];
      }
    }
    if (!$doomed) {
      return $values;
    }

    // Anything nested inside a doomed instance goes with it, transitively —
    // otherwise its subtree would be left dangling.
    $doomed = array_fill_keys(self::expandClosure($tree, $doomed), TRUE);
    $tree = self::pruneTree($tree, $doomed, $policy);
    // The root is required even when it holds nothing.
    $tree[self::ROOT_UUID] ??= [];

    $values['tree'] = $tree;
    if (!empty($values['props'])) {
      $values['props'] = self::backfillProps(array_diff_key($values['props'], $doomed), $tree);
    }
    return $values;
  }

  /**
   * Removes a set of instances from a tree, honouring the empty-section policy.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $doomed
   *   TRUE values keyed by the instance UUIDs being removed.
   * @param \Drupal\neo_alchemist\EmptySectionPolicy $policy
   *   What to do with a slot or section the removal empties.
   *
   * @return array
   *   The pruned tree.
   */
  private static function pruneTree(array $tree, array $doomed, EmptySectionPolicy $policy): array {
    // Drop the subtrees owned by removed instances, then the tuples themselves
    // wherever they sit.
    $tree = array_diff_key($tree, $doomed);
    foreach ($tree as $key => $section) {
      if ($key === self::ROOT_UUID) {
        // array_values() keeps the section a JSON list: array_filter()
        // preserves keys, so dropping any item but the last would otherwise
        // re-encode it as an object.
        $tree[$key] = array_values(array_filter(
          (array) $section,
          fn ($tuple) => !isset($doomed[$tuple['uuid'] ?? '']),
        ));
        continue;
      }
      $kept = [];
      foreach ((array) $section as $slot => $tuples) {
        if (!is_array($tuples)) {
          // Not ours to interpret; leave whatever is there untouched.
          $kept[$slot] = $tuples;
          continue;
        }
        $tuples = array_values(array_filter(
          $tuples,
          fn ($tuple) => !isset($doomed[$tuple['uuid'] ?? '']),
        ));
        if ($tuples || $policy === EmptySectionPolicy::Preserve) {
          $kept[$slot] = $tuples;
        }
      }
      if ($kept) {
        $tree[$key] = $kept;
      }
      else {
        unset($tree[$key]);
      }
    }
    return $tree;
  }

  /**
   * Gives every instance in a tree a props entry, inventing empty ones.
   *
   * The other half of parity: ::pruneTree() guarantees no props entry outlives
   * its instance, this guarantees no instance outlives its props entry.
   *
   * @param array $props
   *   The props keyed by instance UUID.
   * @param array $tree
   *   A decoded component tree.
   *
   * @return array
   *   The props, with an empty entry for every instance that lacked one.
   */
  private static function backfillProps(array $props, array $tree): array {
    foreach (self::collectInstanceUuids($tree) as $uuid) {
      if (!array_key_exists($uuid, $props)) {
        $props[$uuid] = [];
      }
    }
    return $props;
  }

  /**
   * Merges an entity's stored hybrid subset into a field default layout.
   *
   * A pure function of a default layout, a stored subset and a set of anchors:
   * the default structure with the entity-owned custom-region content overlaid.
   *
   * Also reports the **orphans** it detects — stored slots the current anchors
   * no longer own and that the default layout does not carry identically. That
   * covers both a vanished anchor (the key is no longer in the default tree)
   * and an un-flagged one (the key is still present but region_custom was
   * removed); in both cases the entity's authored region content must survive
   * the next save so a config revert or a re-flag restores it. Orphans are
   * kept out of the merged runtime value and re-emitted by
   * ::extractHybridStorage().
   *
   * The default-layout comparison is what distinguishes "a full merged tree
   * passed through" (non-anchor default sections arrive verbatim and must NOT
   * be stashed) from genuinely entity-authored content: hybrid locks inherited
   * instances, so a non-anchor section can only diverge from the default when
   * it was authored inside a formerly-flagged region. Loose == tolerates
   * key-order differences while catching any content divergence.
   *
   * @param array $defaults
   *   The field default layout, with 'tree' and 'props' keys.
   * @param array $storedTree
   *   The decoded stored tree. A root section, if present, is dropped: the
   *   default layout is authoritative for root-level structure, and leaving it
   *   in would make the merge report the whole default as entity-authored
   *   orphans the second time a value passed through.
   * @param array $storedProps
   *   The decoded stored props.
   * @param array $anchors
   *   The custom-region anchors.
   *
   * @return array
   *   ['tree' => merged tree, 'props' => merged props, 'orphans' =>
   *   ['tree' => …, 'props' => …]], all decoded arrays.
   */
  public static function composeHybrid(array $defaults, array $storedTree, array $storedProps, array $anchors): array {
    unset($storedTree[self::ROOT_UUID]);
    $defaultTree = $defaults['tree'] ?? [self::ROOT_UUID => []];
    $defaultProps = $defaults['props'] ?? [];

    $mergedTree = $defaultTree;
    $mergedProps = $defaultProps;
    $ownedUuids = self::collectAnchorClosure($storedTree, $anchors);
    $ownedFlip = array_flip($ownedUuids);
    $orphans = ['tree' => [], 'props' => []];

    foreach ($anchors as $ownerUuid => $anchor) {
      if (!array_key_exists($ownerUuid, $storedTree)) {
        // The anchor is not present in the stored value — it was added to the
        // default layout after this entity was last saved. Its default seed
        // children apply.
        continue;
      }
      foreach ($anchor['slots'] ?? [] as $slotId) {
        // The stored value is authoritative for this flagged slot: drop the
        // default seed closure before overlaying.
        $seedTuples = $defaultTree[$ownerUuid][$slotId] ?? [];
        foreach (self::expandClosure($defaultTree, array_column($seedTuples, 'uuid')) as $seedUuid) {
          unset($mergedTree[$seedUuid], $mergedProps[$seedUuid]);
        }
        // Always written, empty included. An emptied flagged slot is a
        // creator's decision, and the merged tree has to keep saying so:
        // dropping the key made it indistinguishable from an anchor that
        // postdates the stored value, and this same function reads that as
        // "the default seed children apply". A merged value re-composed once —
        // which is what a second draft save does — brought the site builder's
        // seed content back into a region the creator had emptied.
        //
        // Keeping it is also what makes the merge idempotent, the property
        // ARCHITECTURE.md claims and HybridRoundTripTest now enforces.
        $mergedTree[$ownerUuid][$slotId] = array_values($storedTree[$ownerUuid][$slotId] ?? []);
      }
    }

    // Overlay the entity-owned descendant sections and props.
    foreach ($ownedUuids as $uuid) {
      if (isset($storedTree[$uuid])) {
        $mergedTree[$uuid] = $storedTree[$uuid];
      }
      if (array_key_exists($uuid, $storedProps)) {
        $mergedProps[$uuid] = $storedProps[$uuid];
      }
    }

    // Detect orphaned content, slot by slot.
    $defaultFlip = array_flip(self::collectUuids($defaultTree));
    foreach ($storedTree as $key => $section) {
      if (isset($ownedFlip[$key])) {
        continue;
      }
      foreach ((array) $section as $slotId => $tuples) {
        if (isset($anchors[$key]) && in_array($slotId, $anchors[$key]['slots'] ?? [], TRUE)) {
          // Currently flagged: the anchors merge loop above owns this slot.
          continue;
        }
        if (isset($defaultFlip[$key]) && ($defaultTree[$key][$slotId] ?? []) == $tuples) {
          // Identical to the default layout — nothing entity-authored here.
          continue;
        }
        $orphans['tree'][$key][$slotId] = $tuples;
        foreach ((array) $tuples as $tuple) {
          $uuid = $tuple['uuid'] ?? NULL;
          if ($uuid && array_key_exists($uuid, $storedProps)) {
            $orphans['props'][$uuid] = $storedProps[$uuid];
          }
        }
      }
    }

    return [
      'tree' => $mergedTree,
      'props' => $mergedProps,
      'orphans' => $orphans,
    ];
  }

  /**
   * Extracts the entity-owned storage subset from a merged hybrid value.
   *
   * The inverse of ::composeHybrid(). Once an entity is customized the stored
   * value is authoritative for every custom region: each flagged slot is
   * always written (an empty slot means "explicitly empty"), so an anchor
   * missing from storage can only mean it was added to the default layout
   * after this entity was last saved.
   *
   * Tree↔props parity is a postcondition rather than something the caller
   * backfills to appease the save-time check. Instances are tuple uuids — a
   * container inside a custom region has its own section AND is a tuple, and
   * it is exactly the case that must be backfilled. Anchor owners appear only
   * as section keys (the subset root is empty) and are not instances, so they
   * get no entry.
   *
   * @param array $tree
   *   The merged tree.
   * @param array $props
   *   The merged props.
   * @param array $anchors
   *   The custom-region anchors.
   * @param array $orphans
   *   The preserved orphans, keyed 'tree' and 'props'.
   *
   * @return array
   *   ['tree' => …, 'props' => …], both decoded arrays.
   */
  public static function extractHybridStorage(array $tree, array $props, array $anchors, array $orphans = []): array {
    $storageTree = [self::ROOT_UUID => []];
    $storageProps = [];
    $ownedUuids = self::collectAnchorClosure($tree, $anchors);
    foreach ($anchors as $ownerUuid => $anchor) {
      foreach ($anchor['slots'] ?? [] as $slotId) {
        $storageTree[$ownerUuid][$slotId] = array_values($tree[$ownerUuid][$slotId] ?? []);
      }
    }
    foreach ($ownedUuids as $uuid) {
      if (isset($tree[$uuid])) {
        $storageTree[$uuid] = $tree[$uuid];
      }
      if (array_key_exists($uuid, $props)) {
        $storageProps[$uuid] = $props[$uuid];
      }
    }
    // Re-emit preserved orphans, slot by slot. A partially-anchored owner (one
    // region still flagged, another un-flagged) already has a storage section
    // for its live slots, so a whole-section guard would never fire and the
    // orphaned slot would be lost on save.
    foreach ($orphans['tree'] ?? [] as $key => $section) {
      foreach ((array) $section as $slotId => $tuples) {
        if (!isset($storageTree[$key][$slotId])) {
          $storageTree[$key][$slotId] = $tuples;
        }
      }
    }
    foreach ($orphans['props'] ?? [] as $uuid => $value) {
      if (!array_key_exists($uuid, $storageProps)) {
        $storageProps[$uuid] = $value;
      }
    }
    return [
      'tree' => $storageTree,
      'props' => self::backfillProps($storageProps, $storageTree),
    ];
  }

}
