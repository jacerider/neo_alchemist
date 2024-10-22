<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;
use Drupal\neo_alchemist\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement;
use Symfony\Component\Validator\Constraint;

/**
 * @todo Add class description.
 */
final class FieldMatcher {

  /**
   * Constructs a FieldMatcher object.
   */
  public function __construct(
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly ConstraintManager $constraintManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  public function getMatchesAsOptions(ComponentShapePluginInterface $shape): array {
    $matches = $this->getMatches($shape);
    $host_entity_type_id = $shape->getEntityType();
    $host_entity_type_bundle = $shape->getEntityBundle();
    if ($host_entity_type_id) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($host_entity_type_id, $host_entity_type_bundle);
      $matches = array_combine(
        array_map(
          function (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $e) use ($field_definitions, $host_entity_type_id, $host_entity_type_bundle) {
            $field_name = $e instanceof ReferenceFieldPropExpression
              ? $e->referencer->fieldName
              : $e->fieldName;
            // ksm(get_class($e), $field_name, $e->fieldName, (string) $e);
            $field_definition = $field_definitions[$field_name];
            assert($field_definition instanceof FieldDefinitionInterface);
            return (string) t("This @entity's @field-label", [
              '@entity' => $this->entityTypeBundleInfo->getBundleInfo($host_entity_type_id)[$host_entity_type_bundle]['label'],
              '@field-label' => $field_definition->getLabel(),
            ]);
          },
          $matches,
        ),
      $matches);
    }
    return $matches;
  }

  /**
   * @todo Add method description.
   */
  public function getMatches(ComponentShapePluginInterface $shape): array {
    $matches = [];

    if ($entityType = $shape->getEntityType()) {
      $dataType = implode(':', array_filter([
        'entity',
        $entityType,
        $shape->getEntityBundle(),
      ]));
      $entity_data_definition = EntityDataDefinition::createFromDataType($dataType);
    }
    else {
      return $matches;
    }

    $matches = $this->matchEntityProps($entity_data_definition, 1, $shape);
    uasort($matches, [SortArray::class, 'sortByTitleElement']);
    return $matches;
  }

  private function matchEntityProps(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, ComponentShapePluginInterface $shape, array $parent_definitions = []): array {
    $matches = [];
    if ($shape->isScalar()) {
      $matches = $this->matchEntityPropsForScalar($entity_data_definition, $levels_to_recurse, $shape, $parent_definitions);
    }
    else {
      // assert(is_array($shape->getSchema()));
      // return $this->matchEntityPropsForIterable($entity_data_definition, $levels_to_recurse, $shape);
    }
    return $matches;
  }

  /**
   * @todo Add method description.
   */
  private function matchEntityPropsForIterable(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, ComponentShapePluginInterface $shape): array {
    if (!$shape->isIterable()) {
      throw new \LogicException();
    }
    $matches = [];

    $required_object_props = [];
    $all_object_props = [];
    $object_prop_matches = [];
    foreach ($this->iterateJsonSchema($shape->getSchema()) as $name => ['required' => $sub_required, 'schema' => $sub_schema]) {
    }

    ksm('hit');
    return $matches;
  }

  /**
   * @todo Add method description.
   */
  private function matchEntityPropsForScalar(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, ComponentShapePluginInterface $shape, array $parent_definitions = []): array {
    $matches = [];

    $is_required_in_json_schema = $shape->isRequired();
    $field_definitions = $this->recurseDataDefinitionInterface($entity_data_definition);
    // ksm($field_definitions);

    foreach ($field_definitions as $field_definition) {
      assert($field_definition instanceof FieldDefinitionInterface);
      if ($is_required_in_json_schema && !$field_definition->isRequired()) {
        continue;
      }
      $field_parent_definitions = array_merge($parent_definitions, [$field_definition]);
      // This matches string to string. But should we allow the shape to
      // determine its match.
      // ksm($field_definition->getType(), $field_definition->getFieldStorageDefinition()->getType(), $shape->getType(), $shape->getRef());
      if ($field_definition->getFieldStorageDefinition()->getType() === $shape->getRef()) {
      // if ($field_definition->getType() === $shape->getType()) {
        if ($shape->isRequired() && !$field_definition->isRequired()) {
          continue;
        }
        // ksm((string) $field_definition->getLabel());
        $matches[$this->getDefinitionsKey($field_parent_definitions)] = [
          'title' => $this->getDefinitionsLabel($field_parent_definitions),
          'definition' => $field_definition,
        ];
      }
      $properties = $this->recurseDataDefinitionInterface($field_definition);
      foreach ($properties as $property_name => $property_definition) {
        $is_reference = $this->dataLeafIsReference($property_definition);
        if ($is_reference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        if ($is_reference) {
          if ($levels_to_recurse === 0) {
            continue;
          }
          if ($property_definition instanceof DataReferenceDefinitionInterface && is_a($property_definition->getClass(), EntityReference::class, TRUE)) {
            $target = $property_definition->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            $matches += $this->matchEntityProps($target, $levels_to_recurse - 1, $shape, $field_parent_definitions);
          }
        }
      }
    }

    return $matches;
  }

  /**
   * Get the label for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   *
   * @return string
   *   The key.
   */
  private function getDefinitionsLabel(array $definitions): string {
    return implode(' → ', array_map(fn ($d) => $d->getLabel() . ' (' . $d->getTargetEntityTypeId() . ')', $definitions));
  }

  /**
   * Get the key for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   *
   * @return string
   *   The key.
   */
  private function getDefinitionsKey(array $definitions): string {
    return implode('.', array_map(fn ($d) => $d->getName(), $definitions));
  }

  /**
   * @return \Drupal\Core\TypedData\DataDefinitionInterface[]
   */
  private function recurseDataDefinitionInterface(DataDefinitionInterface $dd): array {
    return match (TRUE) {
      // Entity level.
      $dd instanceof EntityDataDefinitionInterface => (function ($dd) {
        if ($dd->getClass() === ConfigEntityAdapter::class) {
          // @todo load config entity type, look at export properties?
          return [];
        }
        assert($dd->getClass() === EntityAdapter::class);
        $entity_type_id = $dd->getEntityTypeId();
        assert(is_string($entity_type_id));
        // If no bundles or multiple bundles are specified, inspect the base
        // fields. Otherwise (if a single bundle is specified), inspect all
        // fields.
        if ($dd->getBundles() !== NULL && count($dd->getBundles()) === 1) {
          return $this->entityFieldManager->getFieldDefinitions($entity_type_id, $dd->getBundles()[0]);
        }
        return $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
      })($dd),
      // Field level.
      $dd instanceof FieldDefinitionInterface => $this->recurseDataDefinitionInterface($dd->getItemDefinition()),
      $dd instanceof FieldItemDataDefinitionInterface => $dd->getPropertyDefinitions(),
      default => throw new \LogicException('Unhandled.'),
    };
  }

  /**
   * @todo Add method description.
   */
  private function dataLeafIsReference(TypedDataInterface|DataDefinitionInterface $td_or_dd): ?bool {
    if ($td_or_dd instanceof TypedDataInterface && !$td_or_dd->getParent() instanceof FieldItemInterface) {
      throw new \LogicException(__METHOD__ . ' was given a non-leaf.');
    }
    $dd = $td_or_dd instanceof TypedDataInterface
      ? $td_or_dd->getDataDefinition()
      : $td_or_dd;
    return match(TRUE) {
      $dd instanceof DataReferenceDefinitionInterface => TRUE,
      is_a($dd->getClass(), PrimitiveInterface::class, TRUE) => FALSE,
      // Anything else cannot be handled and merits logging.
      TRUE => (function ($td_or_dd) {
        match (TRUE) {
          // PHPStan does not like this because getParent()->getDataDefinition()
          // only has a less specific type guarantee. But … this is just for
          // logging unhandled cases. This is sufficient as-is.
          // @phpstan-ignore-next-line
          $td_or_dd instanceof TypedDataInterface => @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type `%s` property uses `%s` data type class that is not yet supported", $td_or_dd->getParent()->getDataDefinition()->getFieldDefinition()->getType(), $td_or_dd->getName(), $td_or_dd->getDataDefinition()->getClass()), E_USER_DEPRECATED),
          $td_or_dd instanceof DataDefinitionInterface => @trigger_error(sprintf("Unhandled data type class: `%s` data type class that is not yet supported", $td_or_dd->getClass()), E_USER_DEPRECATED),
        };
        return NULL;
      })($td_or_dd),
    };
  }

}
