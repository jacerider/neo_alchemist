<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Validation\Constraint;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validates the structure of a component tree.
 *
 * This validator ensures that the structure of a component tree adheres to the
 * expected constraints. The component tree is represented as a JSON string
 * where the root UUID and other component subtrees are validated.
 *
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ComponentTreeStructureConstraint
 */
final class ComponentTreeStructureConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * ComponentTreeStructureConstraintValidator constructor.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $componentStorage
   *   The storage handler for configuration entities.
   * @param \Drupal\neo_alchemist\Plugin\Validation\Constraint\BasicRecursiveValidatorFactory $validatorFactory
   *   The factory for creating recursive validators.
   *
   * @throws \AssertionError
   *   Thrown when the component storage is not 'neo_component'.
   *
   * @see \Drupal\neo_alchemist\Entity\Component
   */
  public function __construct(
    private readonly ConfigEntityStorageInterface $componentStorage,
    private readonly BasicRecursiveValidatorFactory $validatorFactory,
  ) {
    // @see \Drupal\neo_alchemist\Entity\Component
    assert($this->componentStorage->getEntityTypeId() === 'neo_component');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $component_storage = $container->get(EntityTypeManagerInterface::class)->getStorage('neo_component');
    assert($component_storage instanceof ConfigEntityStorageInterface);
    return new static(
      $component_storage,
      $container->get(BasicRecursiveValidatorFactory::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!is_string($value)) {
      throw new \UnexpectedValueException(sprintf('The value must be a string, found %s.', gettype($value)));
    }
    $tree = json_decode($value, TRUE);
    if ($tree === NULL) {
      throw new \UnexpectedValueException(sprintf('The value must be a valid JSON string, found %s.', $value));
    }
    // Hybrid mode stores a machine-written subset on entities:
    // custom-region sections whose owner instance lives in the field default
    // layout (dangling by design), explicitly-empty flagged slots, and
    // preserved orphan sections. Relax the strictness for that case only.
    $hybrid = $this->isHybridEntityScope();
    if ($hybrid) {
      $tree = self::filterEmptySections($tree);
    }
    $this->validateTree($tree);
    if ($hybrid) {
      return;
    }
    foreach (array_keys($tree) as $uuid) {
      assert(is_string($uuid));
      if ($uuid === ComponentTreeStructure::ROOT_UUID) {
        continue;
      }
      if (!self::isUuidInTree($tree, $uuid)) {
        $this->context->buildViolation("Dangling component subtree. This component subtree claims to be for a component instance with UUID %uuid, but no such component instance can be found.")
          ->setParameter('%uuid', $uuid)
          ->atPath("[$uuid]")
          ->addViolation();
      }
    }
  }

  /**
   * Checks if the validated tree belongs to a hybrid field on an entity.
   *
   * @return bool
   *   TRUE when the tree is entity-scope data of a hybrid field.
   */
  private function isHybridEntityScope(): bool {
    $object = $this->context->getObject();
    if (!$object instanceof ComponentTreeStructure) {
      return FALSE;
    }
    $item = $object->getParent();
    if (!$item instanceof ComponentTreeItem) {
      return FALSE;
    }
    $list = $item->getParent();
    if (!$list instanceof NeoComponentTreeList || $list->belongsToFieldConfig()) {
      return FALSE;
    }
    $definition = $item->getFieldDefinition();
    return $definition instanceof ComponentFieldConfigInterface && $definition->isHybrid();
  }

  /**
   * Removes empty slots and sections from a tree copy prior to validation.
   *
   * Hybrid storage legitimately contains empty flagged slots ("explicitly
   * empty" markers); they must not trigger the empty-subtree violations.
   *
   * @param array $tree
   *   The decoded tree.
   *
   * @return array
   *   The tree without empty non-root slots/sections.
   */
  private static function filterEmptySections(array $tree): array {
    foreach ($tree as $key => $section) {
      if ($key === ComponentTreeStructure::ROOT_UUID) {
        continue;
      }
      $section = array_filter((array) $section);
      if ($section) {
        $tree[$key] = $section;
      }
      else {
        unset($tree[$key]);
      }
    }
    return $tree;
  }

  /**
   * Checks if a given UUID exists within a component tree structure.
   *
   * This method traverses a nested array structure representing a component
   * tree and checks if the specified UUID is present. The tree can have a root
   * level and multiple subtrees, each containing components identified by
   * UUIDs.
   *
   * @param array $tree
   *   The component tree structure to search within. The tree is an associative
   *   array where keys are UUIDs and values are either arrays of components or
   *   further nested arrays representing subtrees.
   * @param string $uuid
   *   The UUID to search for within the tree.
   *
   * @return bool
   *   TRUE if the UUID is found within the tree, FALSE otherwise.
   */
  private static function isUuidInTree(array $tree, string $uuid): bool {
    foreach ($tree as $top_level_uuid => $component_subtree) {
      if ($top_level_uuid === $uuid) {
        // Do not search for the UUID in its own component subtree.
        continue;
      }
      if ($top_level_uuid === ComponentTreeStructure::ROOT_UUID) {
        // The root subtree contains "uuid-component" tuples directly.
        if (in_array($uuid, array_column($component_subtree, 'uuid'), TRUE)) {
          return TRUE;
        }
      }
      else {
        // Non-root subtrees contain slots and "uuid,component" tuples in
        // each slot.
        foreach ($component_subtree as $slots) {
          if (in_array($uuid, array_column($slots, 'uuid'), TRUE)) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Validates the structure of a component tree.
   *
   * This method validates the structure of a component tree represented as a
   * plain array-based data structure. It ensures that the root UUID and other
   * component subtrees adhere to the expected constraints.
   *
   * @param array $tree
   *   The component tree to validate.
   *
   * @throws \Symfony\Component\Validator\Exception\ValidatorException
   *   If the validation fails.
   *
   * @todo Re-assess the use of TypedData objects in
   *   https://www.drupal.org/project/neo_alchemist/issues/3462235. If TypedData
   *   objects are introduced, this validation could be simplified.
   */
  private function validateTree(array $tree): void {
    // TRICKY: The existing validator and execution context cannot be reused
    // because Drupal expects everything to be TypedData, whereas here it is a
    // plain array-based data structure.
    // @todo Re-assess this in https://www.drupal.org/project/neo_alchemist/issues/3462235: if that introduces TypedData objects, then this could be simplified.
    $non_typed_data_validator = $this->validatorFactory->createValidator();

    // Constraint to validate each component instance, which is represented in
    // the tree by a "uuid,component" tuple.
    $component_instance_constraint = new Sequentially(
      [
        new Collection([
          'uuid' => new Required([
            new Type('string'),
            // @todo Validate that the string is a valid UUID. *And* that it is unique in the tree.
            new NotBlank(),
          ]),
          'component' => new Required([
            new Type('string'),
            new NotBlank(),
          ]),
        ]),
        new Callback(
          callback: self::validateComponentInstance(...),
          payload: [
            'component_storage' => $this->componentStorage,
          ]
        ),
      ]
    );
    // Since the root UUID has a different expected structure than other UUIDs
    // at the top of the data structure we validate it first to avoid overly
    // complicated constraints.
    $root_constraints = new Collection(
      [
        ComponentTreeStructure::ROOT_UUID => new Required([
          new Type('array'),
          new All([$component_instance_constraint]),
        ],
        ),
      ],
      missingFieldsMessage: 'The root UUID is missing.'
    );
    $root_constraints->allowExtraFields = TRUE;
    $violations = $non_typed_data_validator->validate($tree, $root_constraints);

    // Finally, validate all other component subtrees.
    unset($tree[ComponentTreeStructure::ROOT_UUID]);
    $other_subtrees_constraints = new All([
      new Count(['min' => 1], minMessage: 'Empty component subtree. A component subtree must contain >=1 populated slot (with >=1 component instance). Empty component subtrees must be omitted.'),
      new All([
        new Sequentially([
          new Type('array'),
          new Count(['min' => 1], minMessage: 'Empty slot. Slots without component instances must be omitted.'),
          new All([$component_instance_constraint]),
        ]),
      ]),
    ]);
    $violations->addAll($non_typed_data_validator->validate($tree, $other_subtrees_constraints));

    foreach ($violations as $violation) {
      $this->context->buildViolation((string) $violation->getMessage())
        ->atPath($violation->getPropertyPath())
        ->addViolation();
    }
  }

  /**
   * Validates a component instance.
   *
   * This method checks the validity of a given component instance within the
   * context of a component tree structure. It ensures that the component
   * exists, has the necessary properties, and that the component subtree
   * adheres to the expected structure.
   *
   * @param array $component_instance
   *   The component instance to validate.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation context.
   * @param array $payload
   *   Additional data required for validation, including:
   *   - 'component_storage': The storage handler for component entities.
   *
   * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException
   *   Thrown when the storage does not handle 'neo_component' entities.
   */
  private static function validateComponentInstance(array $component_instance, ExecutionContextInterface $context, array $payload): void {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $component_storage */
    $component_storage = $payload['component_storage'];
    assert($component_storage->getEntityTypeId() === 'neo_component');
    // /** @var \Drupal\Core\Theme\ComponentPluginManager $component_manager */
    // $component_manager = $payload['component_manager'];
    $tree = $context->getRoot();

    if (!isset($component_instance['component'])) {
      // The \Symfony\Component\Validator\Constraints\Collection constraint
      // will add the violations for the unset key.
      return;
    }

    /** @var \Drupal\neo_alchemist\ComponentInterface $component_config_entity */
    $component_config_entity = $component_storage->load($component_instance['component']);
    if ($component_config_entity === NULL) {
      $context->addViolation('The component %component does not exist.', ['%component' => $component_instance['component']]);
      return;
    }
    if (!isset($component_instance['uuid'])) {
      // The \Symfony\Component\Validator\Constraints\Collection constraint
      // will add the violations for the unset key.
      return;
    }

    // Override property path, for more meaningful validation errors.
    $original_property_path = $context->getPropertyPath();
    $context->setNode(
      $context->getValue(),
      $context->getObject(),
      $context->getMetadata(),
      '',
    );

    // @todo This will need to evolve when supporting non-SDC component types in https://www.drupal.org/project/neo_alchemist/issues/3454519
    // $component = $component_manager->find(Component::convertIdToMachineName($component_instance['component']));
    $component = $component_config_entity->getComponent();
    if (empty($component->metadata->slots)) {
      if (isset($tree[$component_instance['uuid']])) {
        $context->buildViolation('Invalid component subtree. A component subtree must only exist for components with >=1 slot, but the component %component has no slots, yet a subtree exists for the instance with UUID %uuid.', [
          '%component' => $component_instance['component'],
          '%uuid' => $component_instance['component'],
        ])
          ->atPath('[' . $component_instance['uuid'] . ']')
          ->addViolation();
      }
    }
    elseif (isset($tree[$component_instance['uuid']])) {
      $tree_slot_info = $tree[$component_instance['uuid']];
      $actual_slot_names = array_keys($component->metadata->slots);
      $unknown_slot_names = array_diff(array_keys($tree_slot_info), $actual_slot_names);
      foreach ($unknown_slot_names as $unknown_slot_name) {
        $context->buildViolation('Invalid component subtree. This component subtree contains an invalid slot name for component %component: %invalid_slot_name. Valid slot names are: %valid_slot_names.', [
          '%component' => $component_instance['component'],
          '%invalid_slot_name' => $unknown_slot_name,
          '%valid_slot_names' => implode(', ', $actual_slot_names),
        ])
          ->atPath('[' . $component_instance['uuid'] . '][' . $unknown_slot_name . ']')
          ->addViolation();
      }
    }

    // Restore property path.
    $context->setNode(
      $context->getValue(),
      $context->getObject(),
      $context->getMetadata(),
      $original_property_path,
    );
  }

}
