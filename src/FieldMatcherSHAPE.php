<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

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
      $entity_data_definition = $shape->getFieldItem()->getFieldDefinition();
    }
    $matches = $this->matchEntityProps($entity_data_definition, 1, $shape);
    /** @var array<\Drupal\neo_alchemist\PropExpressions\StructuredData\FieldPropExpression|\Drupal\neo_alchemist\PropExpressions\StructuredData\ReferenceFieldPropExpression|\Drupal\neo_alchemist\PropExpressions\StructuredData\FieldObjectPropsExpression> */
    $keyed_by_string = array_combine(array_map(fn ($e) => (string) $e, $matches), $matches);
    ksort($keyed_by_string);
    return array_values($keyed_by_string);
  }

  private function matchEntityProps(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, ComponentShapePluginInterface $shape): array {
    $matches = [];
    if ($shape->isScalar()) {
      $matches = $this->matchEntityPropsForScalar($entity_data_definition, $levels_to_recurse, $shape);
    }
    else {
      assert(is_array($shape->getSchema()));
      return $this->matchEntityPropsForIterable($entity_data_definition, $levels_to_recurse, $shape);
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
  private function matchEntityPropsForScalar(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, ComponentShapePluginInterface $shape): array {
    $matches = [];

    $is_required_in_json_schema = $shape->isRequired();
    $field_definitions = $this->recurseDataDefinitionInterface($entity_data_definition);
    foreach ($field_definitions as $field_definition) {
      assert($field_definition instanceof FieldDefinitionInterface);
      if ($is_required_in_json_schema && !$field_definition->isRequired()) {
        continue;
      }
      $properties = $this->recurseDataDefinitionInterface($field_definition);
      foreach ($properties as $property_name => $property_definition) {
        $is_reference = $this->dataLeafIsReference($property_definition);
        if ($is_reference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        $current_entity_field_prop = new FieldPropExpression(
          $entity_data_definition,
          $field_definition->getName(),
          NULL,
          (string) $property_name,
        );
        if ($is_reference) {
          if ($levels_to_recurse === 0) {
            continue;
          }
          // Only follow entity references, as deep as specified.
          // @see ::findFieldTypeStorageCandidates()
          if ($property_definition instanceof DataReferenceDefinitionInterface && is_a($property_definition->getClass(), EntityReference::class, TRUE)) {
            $target = $property_definition->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            // When referencing an entity, enrich the EntityDataDefinition with
            // constraints that are imposed by the entity reference field, to
            // narrow the matching.
            // @todo Generalize this so it works for all entity reference field types that do not allow *any* entity of the target entity type to be selected
            if (is_a($field_definition->getItemDefinition()->getClass(), FileItem::class, TRUE)) {
              $field_item = $this->typedDataManager->createInstance("field_item:" . $field_definition->getType(), [
                'name' => $field_definition->getName(),
                'parent' => NULL,
                'data_definition' => $field_definition->getItemDefinition(),
              ]);
              assert($field_item instanceof FileItem);
              $target->addConstraint('FileExtension', $field_item->getUploadValidators()['FileExtension']);
            }
            $referenced_matches = $this->matchEntityProps($target, $levels_to_recurse - 1, $shape);
            foreach ($referenced_matches as $referenced_match) {
              $matches[] = new ReferenceFieldPropExpression($current_entity_field_prop, $referenced_match);
            }
          }
        }
        else {
          $entity_constraints = $entity_data_definition->getConstraints();
          if (!empty($entity_constraints)) {
            // Transform an entity-level `FileExtension` constraint to
            // corresponding property-level constraint.
            // @see \Drupal\file\Plugin\Validation\Constraint\FileExtensionConstraintValidator
            if (array_key_exists('FileExtension', $entity_data_definition->getConstraints()) && is_a($field_definition->getItemDefinition()->getClass(), FileUriItem::class, TRUE)) {
              // Clone to avoid polluting any static caches.
              // @todo verify if truly necessary?
              $transformed_property_data_definition = clone $property_definition;
              // @todo JSON schema does not support case-insensitive matching!!!! https://json-schema.org/understanding-json-schema/reference/regular_expressions
              $trailing_uri_regex_pattern = '\.(' . preg_replace('/ +/', '|', preg_quote($entity_constraints['FileExtension']['extensions'])) . ')(\?.*)?(#.*)?$';
              // If a `Regex` constraint exists, expand it to also match the trailing part.
              // @todo verify the regex constraint currently only matches the leading part.
              if ($regex_constraint = $transformed_property_data_definition->getConstraint('Regex')) {
                assert(str_starts_with($regex_constraint['pattern'], '/^'));
                // Because we are concatenating the regex pattern with another
                // pattern that applies to the end of the line the existing
                // pattern cannot contain a `$` which is the end of line
                // metacharacter.
                // @todo Make this check smarter to handle cases like:
                //   '\$/': should not match because this is literal '$'
                //   '\\$/': should match because '$' is an end of line
                if (str_ends_with($regex_constraint['pattern'], '$/')) {
                  throw new \LogicException(sprintf('The property %s for the field %s uses Regex constraint pattern, %s, that includes an end-of-line metacharacter, `$`,  which is not allowed when also using a FileExtension constraint', $property_name, $regex_constraint['pattern'], $field_definition->getName()));
                }
                assert(str_ends_with($regex_constraint['pattern'], '/'));
                // Trim the trailing slash away. (Using `rtrim()` is incorrect:
                // it would trim _all_ trailing slashes away.)
                $regex_constraint['pattern'] = substr($regex_constraint['pattern'], 0, -1);
                $regex_constraint['pattern'] .= '.*' . $trailing_uri_regex_pattern . '/';
                $transformed_property_data_definition->addConstraint('Regex', $regex_constraint);
              }
              else {
                $transformed_property_data_definition->addConstraint('Regex', [
                  'pattern' => $trailing_uri_regex_pattern,
                ]);
              }
              $property_definition = $transformed_property_data_definition;
            }
          }
          assert(is_a($property_definition->getClass(), PrimitiveInterface::class, TRUE));
          $field_item = $this->typedDataManager->createInstance("field_item:" . $field_definition->getType(), [
            'name' => NULL,
            'parent' => NULL,
            'data_definition' => $field_definition->getItemDefinition(),
          ]);
          $property = $this->typedDataManager->create(
            $property_definition,
            NULL,
            $property_name,
            $field_item,
          );
          if ($this->dataLeafMatchesFormat($property, $shape)) {
            $matches[] = $current_entity_field_prop;
          }
        }
      }
    }
    return $matches;
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
   * @param JsonSchema $schema
   */
  private function dataLeafMatchesFormat(TypedDataInterface $data, ComponentShapePluginInterface $shape): bool {
    if (!$data->getParent()) {
      throw new \LogicException('must be a property with a field item as context for format checking');
    }
    $property_data_definition = $data->getDataDefinition();
    if (!$this->dataDefinitionMatchesPrimitiveType($property_data_definition, $shape)) {
      return FALSE;
    }

    $field_item = $data->getParent();
    assert($field_item instanceof FieldItemInterface);
    $field_property_name = $data->getName();

    // TRICKY: to correctly merge these, these arrays must be rekeyed to allow
    // the field type to override default property-level constraints.
    $rekey = function (array $constraints) {
      return array_combine(
        array_map(
          fn (Constraint $c): string => get_class($c),
          $constraints,
        ),
        $constraints
      );
    };

    // Gather all constraints that apply to this field item property. Note:
    // 1. all field item properties are DataType plugin instances
    // 2. DataType plugin definitions can define constraints
    // 3. all FieldType plugins defines which properties they contain and what
    //    DataType plugins they use in its `::propertyDefinitions()`
    // 4. in that `::propertyDefinitions()`, FieldType plugins can override the
    //    default constraints
    // 5. (per `DataDefinitionInterface::getConstraints()`, each constraint can
    //    be used only once — hence only overriding is possible)
    // 6. FieldType plugins can can narrow a particular use of a DataType
    //    further based on configuration in their `::getConstraints()` method by
    //    adding a `ComplexData` constraint; any constraint added here trumps a
    //    constraint defined at the property level
    //    e.g.: \Drupal\Core\Field\Plugin\Field\FieldType\NumericItemBase::getConstraints()
    // 7. EntityType plugins can similarly narrow the use of a DataType by
    //    calling `::addPropertyConstraints()` in their
    //    `::baseFieldDefinitions()`
    //   e.g.: \Drupal\path_alias\Entity\PathAlias::baseFieldDefinitions()
    // @see \Drupal\Core\TypedData\DataDefinition::addConstraint()
    // @see \Drupal\Core\Field\BaseFieldDefinition::addPropertyConstraints()
    // @see \Drupal\Core\Field\FieldItemInterface::propertyDefinitions()
    // @see \Drupal\Core\TypedData\DataDefinitionInterface::getConstraints()
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\NumericItemBase::getConstraints()
    $property_level_constraints = $rekey($data->getConstraints());
    $field_item_level_constraints = [];
    foreach ($field_item->getConstraints() as $field_item_constraint) {
      if ($field_item_constraint instanceof ComplexDataConstraint) {
        $field_item_level_constraints += $rekey($field_item_constraint->properties[$field_property_name] ?? []);
      }
    }
    $constraints = $field_item_level_constraints + $property_level_constraints;

    $required_shape = $shape->toRequirements();
    if ($required_shape instanceof DataTypeShapeRequirement) {
      if ($required_shape->constraint === 'NOT YET SUPPORTED') {
        $error = sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", (string) $shape->getType(), (string) json_encode($shape->getSchema()));
        @trigger_error($error, E_USER_DEPRECATED);
        return FALSE;
      }
      return $this->dataTypeShapeRequirementMatchesFinalConstraintSet($required_shape, $property_data_definition, $constraints);
    }
    else {
      // If there's >1 requirement, they must all be met.
      foreach ($required_shape->requirements as $r) {
        if (!$this->dataTypeShapeRequirementMatchesFinalConstraintSet($r, $property_data_definition, $constraints)) {
          if ($r->constraint === 'NOT YET SUPPORTED') {
            $error = sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", (string) $shape->getType(), (string) json_encode($shape->getSchema()));
            @trigger_error($error, E_USER_DEPRECATED);
            return FALSE;
          }
          return FALSE;
        }
      }
      return TRUE;
    }
  }

  /**
   * @param array<string, \Symfony\Component\Validator\Constraint> $constraints
   */
  private function dataTypeShapeRequirementMatchesFinalConstraintSet(DataTypeShapeRequirement $required_shape, DataDefinitionInterface $property_data_definition, array $constraints): bool {
    // Any data type that is more complex than a primitive is not accepted.
    // For example: `entity_reference`, `language_reference`, etc.
    // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference
    if (!is_a($property_data_definition->getClass(), PrimitiveInterface::class, TRUE)) {
      throw new \LogicException();
    }

    // Is the data shape requirement met?
    // 1. Constraint.
    $constraint_found = in_array(
      $this->constraintManager->create($required_shape->constraint, $required_shape->constraintOptions),
      $constraints
    );
    // 2. Optionally: the interface.
    $interface_found = $required_shape->interface === NULL
      || is_a($property_data_definition->getClass(), $required_shape->interface, TRUE);
    return $constraint_found && $interface_found;
  }

  /**
   * @param JsonSchema $schema
   */
  private function dataDefinitionMatchesPrimitiveType(DataDefinitionInterface $data_definition, ComponentShapePluginInterface $shape): bool {
    $data_type_class = $data_definition->getClass();

    // Any data type that is more complex than a primitive is not accepted.
    // For example: `entity_reference`, `language_reference`, etc.
    // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference
    if (!is_a($data_type_class, PrimitiveInterface::class, TRUE)) {
      throw new \LogicException();
    }

    $field_primitive_types = match (TRUE) {
      is_a($data_type_class, StringData::class, TRUE) => ['string'],
      // TRICKY: a SDC prop that accepts number, can accept both an integer and a
      // float, but an SDC prop that accepts integer, can accept only integer.
      is_a($data_type_class, IntegerData::class, TRUE) => ['integer', 'number'],
      is_a($data_type_class, FloatData::class, TRUE) => ['number'],
      is_a($data_type_class, BooleanData::class, TRUE) => ['boolean'],
      // @todo object + array
      // - for object: initially support only a single level of nesting, then we can expect HERE a ComplexDataInterface with only primitives underneath (hence all leaves)
      // - for array: ListDefinitionInterface
      TRUE => [],
    };

    // If the primitive type does not match, this is not a candidate.
    if (!in_array($shape->getType(), $field_primitive_types)) {
      return FALSE;
    }

    // If it is required in SDC's JSON schema, it must be required in Drupal's
    // Typed Data too; otherwise there is a risk of violating SDC's schema.
    if ($shape->isRequired() && !$data_definition->isRequired()) {
      return FALSE;
    }

    return TRUE;
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

  /**
   * @param array $schema
   */
  public function iterateJsonSchema(array $schema): \Generator {
    $schema = self::resolveSchemaReferences($schema);
    $primitive_type = SdcPropJsonSchemaType::from(
    // TRICKY: SDC always allowed `object` for Twig integration reasons.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      is_array($schema['type']) ? $schema['type'][0] : $schema['type']
    );

    if (!$primitive_type->isIterable()) {
      throw new \LogicException('Can only iterate iterable JSON schema types: array or object.');
    }

    if ($primitive_type === SdcPropJsonSchemaType::OBJECT) {
      foreach ($schema['properties'] ?? [] as $prop_name => $prop_schema) {
        yield $prop_name => [
          // @see https://json-schema.org/understanding-json-schema/reference/object#required
          // @see https://json-schema.org/learn/getting-started-step-by-step#required
          'required' => in_array($prop_name, $schema['required'] ?? [], TRUE),
          'schema' => self::resolveSchemaReferences($prop_schema),
        ];
      }
    }
    else {
      throw new \LogicException('Support for "array" props is not yet implemented.');
    }
  }

  /**
   * @todo Make *recursive* references work in justinrainbow/schema, see https://git.drupalcode.org/project/ui_patterns/-/blob/28cf60dd776fb349d9520377afa510b0d85f3334/src/SchemaManager/ReferencesResolver.php
   *
   * @param array $schema
   * @return array
   *
   * @see \Drupal\neo_alchemist\Plugin\Adapter\AdapterBase::resolveSchemaReferences
   */
  private static function resolveSchemaReferences(array $schema): array {
    return $schema;
  }

}
