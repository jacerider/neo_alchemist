<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\MissingHostEntityException;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ValidComponentTree constraint.
 */
final class ValidComponentTreeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use ConfigComponentTreeTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ComponentValidator $componentValidator,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(ComponentPluginManager::class),
      $container->get(ComponentValidator::class),
      $container->get(TypedDataManagerInterface::class),
    );
  }

  /**
   * Validates the given value against the specified constraint.
   *
   * @param mixed $value
   *   The value to validate. It can be NULL, an instance of ComponentTreeItem,
   *   or an array.
   * @param \Symfony\Component\Validator\Constraint $constraint
   *   The constraint against which to validate the value.
   *
   * @throws \UnexpectedValueException
   *   Thrown when the value is not a ComponentTreeItem object or an array, or
   *   when the tree field does not contain a ComponentTreeStructure object.
   * @throws \Drupal\neo_alchemist\MissingHostEntityException
   *   Thrown when a required field must be populated but is not.
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if ($value === NULL) {
      return;
    }

    if (!$value instanceof ComponentTreeItem && !is_array($value)) {
      throw new \UnexpectedValueException(sprintf('The value must be a ComponentTreeItem object or an array, found %s.', gettype($value)));
    }

    // Validate the raw structure.
    if (!$this->validateRawStructure(is_array($value) ? $value : $value->toArray())) {
      // ::validateRawStructure()'s validation errors should be fixed first.
      return;
    }

    // Validate in-depth. This is simpler if the ComponentTreeItem-provided
    // infrastructure is available, so conjure one from $value if not already.
    if (!$value instanceof ComponentTreeItem) {
      assert(array_key_exists('tree', $value));
      assert(array_key_exists('props', $value));
      $component_tree_type = 'config';
      $value = $this->conjureFieldItemObject($value);
    }
    else {
      $component_tree_type = 'content';
    }
    $tree = $value->get('tree');
    if (!$tree instanceof ComponentTreeStructure) {
      throw new \UnexpectedValueException(sprintf('The tree field must contain a ComponentTreeStructure object, found %s.', gettype($tree)));
    }

    foreach ($tree->getComponentInstanceUuids() as $component_instance_uuid) {
      $component_id = $tree->getComponentId($component_instance_uuid);
      /** @var \Drupal\neo_alchemist\ComponentInterface $neoComponent */
      $neoComponent = Component::load($component_id);
      if ($neoComponent) {
        try {
          $this->componentValidator->validateProps($neoComponent->getPropValues(), $neoComponent->getComponent());
        }
        catch (ComponentNotFoundException) {
          // The violation for a missing component will be added in the
          // validation of the tree structure.
          // @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
        }
        catch (InvalidComponentException) {
          $this->context->addViolation('The component instance with UUID %uuid uses component %id and receives some invalid props! Put a breakpoint here and figure out why.', [
            '%uuid' => $component_instance_uuid,
            '%id' => $neoComponent->id(),
          ]);
        }
        catch (MissingHostEntityException $e) {
          // DynamicPropSources cannot be validated in isolation, only in the
          // context of a host content entity.
          if ($component_tree_type === 'config') {
            // Silence this exception until this config is used in a content
            // entity.
          }
          // Some component props may not be resolvable yet because required
          // fields do not yet have values specified.
          // @see https://www.drupal.org/project/drupal/issues/2820364
          // @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::postSave()
          elseif ($value->getEntity()->isNew()) {
            // Silence this exception until the required field is populated.
          }
          else {
            // The required field must be populated now (this branch can only be
            // hit when the entity already exists and hence all required fields
            // must have values already), so do not silence the exception.
            throw $e;
          }
        }
      }
    }
  }

  /**
   * Validates that the two required key-value pairs are present.
   *
   * @param array $raw_component_tree_values
   *   The raw component tree values.
   *
   * @return bool
   *   TRUE when valid, FALSE when not. Indicates whether to validate further.
   */
  private function validateRawStructure(array $raw_component_tree_values): bool {
    $is_valid = TRUE;
    if (!array_key_exists('tree', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "tree" key.');
      $is_valid = FALSE;
    }
    if (!array_key_exists('props', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "props" key.');
      $is_valid = FALSE;
    }
    return $is_valid;
  }

}
