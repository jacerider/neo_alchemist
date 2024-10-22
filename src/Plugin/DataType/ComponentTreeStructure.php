<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

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
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\neo_alchemist\Entity\Component
 *
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
 */
#[DataType(
  id: "neo_component_tree_structure",
  label: new TranslatableMarkup("Component tree structure"),
  description: new TranslatableMarkup("The structure of the component tree: without props values"),
  // constraints: [
  //   "ComponentTreeStructure" => [],
  // ]
)]
class ComponentTreeStructure extends TypedData {

  const ROOT_UUID = 'a548b48d-58a8-4077-aa04-da9405a6f418';

  /**
   * The data value.
   *
   * @var string
   */
  protected string $value;

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->tree;
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // Default to a JSON object with only the root key present.
    $this->setValue('{"' . ComponentTreeStructure::ROOT_UUID . '": []}', $notify);
    return $this;
  }

}
