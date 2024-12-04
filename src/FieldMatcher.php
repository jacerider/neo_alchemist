<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Provides methods for matching fields between content entities and shapes.
 */
final class FieldMatcher {

  use StringTranslationTrait;

  /**
   * Constructs a FieldMatcher object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Retrieves the field definition for a given key from the component shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin instance.
   * @param string $key
   *   The key for which the field definition is to be retrieved.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition if found, or NULL if no matching definition exists.
   */
  public function getFieldDefinition(ComponentShapePluginInterface $shape, string $key): ?FieldDefinitionInterface {
    $matches = $this->getMatches($shape);
    return $matches[$key]['definition'] ?? NULL;
  }

  /**
   * Retrieves the value of a specified field from a content entity.
   *
   * This method takes a content entity and a key representing the field path,
   * then returns the value of that field. The key can be a dot-separated string
   * to indicate nested fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity from which to retrieve the field value.
   * @param string $key
   *   The dot-separated string representing the field path.
   *
   * @return mixed
   *   The value of the specified field.
   */
  public function getEntityValue(ContentEntityInterface $entity, $key): mixed {
    $path = explode('.', $key);
    return $this->recurseEntity($entity, $path);
  }

  /**
   * Recursively retrieves the value of a field from a content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity from which to retrieve the field value.
   * @param array $path
   *   An array representing the path to the field. Each element in the array
   *   should be a string in the format 'field_name:property', where 'property'
   *   is optional.
   *
   * @return mixed
   *   The value of the field or property, or an empty array if the field or
   *   property does not exist or is empty.
   */
  private function recurseEntity(ContentEntityInterface $entity, array $path): mixed {
    $value = [];
    $key = array_shift($path);
    [$fieldName, $property, $subProperty] = explode(':', $key . '::');
    if ($fieldName === '_entity' && $property) {
      // Special entity property handling.
      return $this->getEntityDefinitionValue($entity, $property, $subProperty);
    }
    if (!$entity->hasField($fieldName)) {
      return $value;
    }
    $field = $entity->get($fieldName);
    if ($field->isEmpty()) {
      return $value;
    }
    // If we have a reference field and there is a path, recurse.
    if ($field instanceof EntityReferenceFieldItemListInterface && !empty($path)) {
      if (!$field->entity) {
        return $value;
      }
      $value = $this->recurseEntity($field->entity, $path);
    }
    elseif ($field instanceof FieldItemListInterface) {
      $value = $field->getValue();
      if ($property) {
        $value = $value[0][$property] ?? [];
      }
    }
    return $value;
  }

  /**
   * Retrieves the value of a specified entity definition property.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity from which to retrieve the property value.
   * @param string $property
   *   The property to retrieve.
   * @param string|null $subProperty
   *   (optional) The sub-property to retrieve.
   *
   * @return array
   *   The value of the specified entity definition property.
   */
  private function getEntityDefinitionValue(ContentEntityInterface $entity, string $property, string $subProperty = NULL): array {
    return match ($property) {
      'label' => [$entity->label()],
      'link' => $this->getEntityDefinitionLink($entity, $subProperty),
      default => [],
    };
  }

  /**
   * Generates a link definition for a given entity and property.
   *
   * This method creates a URL for the specified property of the given entity,
   * checks if the URL is accessible, and then constructs a link definition
   * array containing the title and URI.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity for which the link is being generated.
   * @param string $property
   *   The property of the entity for which the URL is generated
   *   (e.g., 'canonical').
   *
   * @return array
   *   An associative array containing:
   *   - 'title': The title of the link.
   *   - 'uri': The URI string of the link.
   *   - 'options': An empty array for additional options (currently unused).
   */
  private function getEntityDefinitionLink(ContentEntityInterface $entity, string $property): array {
    $url = $entity->toUrl($property);
    if (!$url || !$url->access()) {
      return [];
    }
    $titleReplacements = ['-', '_', '.'];
    $route = \Drupal::service('router.route_provider')->getRouteByName($url->getRouteName());
    $title = match($property) {
      'canonical' => $route->getDefault('_title') ?? $entity->label(),
      default => $route->getDefault('_title') ?? ucwords(str_replace($titleReplacements, ' ', str_replace('-form', '', $property))),
    };
    return [
      'title' => $title,
      'uri' => $url->toUriString(),
      'options' => [],
    ];
  }

  /**
   * Retrieves matches as options for a given component shape.
   *
   * This method processes the matches obtained from the `getMatches` method
   * and organizes them into an associative array suitable for use as options
   * in a select input or similar UI component. The options are grouped by
   * their respective group names, with each group's name capitalized.
   *
   * @param ComponentShapePluginInterface $shape
   *   The component shape plugin interface instance for which matches are to be
   *   retrieved.
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   *
   * @return array
   *   An associative array of options, grouped by their respective group names.
   *   The array structure is as follows:
   *   [
   *     'GroupName' => [
   *       'key' => 'Title',
   *       ...
   *     ],
   *     ...
   *   ]
   */
  public function getMatchesAsOptions(ComponentShapePluginInterface $shape, string $entityTypeId = NULL, string $entityBundle = NULL): array {
    $options = [];
    $matches = $this->getMatches($shape, $entityTypeId, $entityBundle);
    foreach ($matches as $key => [
      'title' => $title,
      'group' => $group,
    ]) {
      $options[ucwords($group)][$key] = $title;
    }
    return $options;
  }

  /**
   * Gets the matches for the given component shape.
   *
   * This method retrieves the matches for a given component shape by
   * determining the target entity type and bundle, creating an entity data
   * definition, and then matching it against the shape. The matches are then
   * sorted by weight and title.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin interface.
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   *
   * @return array
   *   An array of matches, sorted by weight and title.
   */
  public function getMatches(ComponentShapePluginInterface $shape, string $entityTypeId = NULL, string $entityBundle = NULL): array {
    $matches = [];

    if ($entityTypeId) {
      $dataType = implode(':', array_filter([
        'entity',
        $entityTypeId,
        $entityBundle,
      ]));
      $entityDataDefinition = EntityDataDefinition::createFromDataType($dataType);
    }
    elseif ($entityType = $shape->getTargetEntityType()) {
      $dataType = implode(':', array_filter([
        'entity',
        $entityType,
        $shape->getTargetEntityBundle(),
      ]));
      $entityDataDefinition = EntityDataDefinition::createFromDataType($dataType);
    }
    else {
      return $matches;
    }

    $matches = $this->match($entityDataDefinition, $shape, 1);

    uasort($matches, function ($a, $b) {
      $a_weight = $a['weight'] ?? 0;
      $b_weight = $b['weight'] ?? 0;
      if ($a_weight == $b_weight) {
        $a_label = $a['title'];
        $b_label = $b['title'];
        return strnatcasecmp((string) $a_label, (string) $b_label);
      }
      return ($a_weight < $b_weight) ? 1 : -1;
    });

    return $matches;
  }

  /**
   * Matches the entity data definition with the component shape plugin.
   *
   * This method determines whether the shape is scalar or iterable and
   * delegates the matching process to the appropriate method.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition to match.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin to match against.
   * @param int $level
   *   The current level of matching.
   * @param array $parentDefinitions
   *   (optional) An array of parent definitions. Defaults to an empty array.
   *
   * @return array
   *   An array of matched definitions.
   */
  private function match(EntityDataDefinitionInterface $entityDataDefinition, ComponentShapePluginInterface $shape, int $level, array $parentDefinitions = []): array {
    if ($shape->isScalar()) {
      return $this->matchScalar($entityDataDefinition, $shape, $level, $parentDefinitions);
    }
    else {
      return $this->matchIterable($entityDataDefinition, $shape, $level, $parentDefinitions);
    }
  }

  /**
   * Matches an iterable entity data definition with a component shape plugin.
   *
   * This method attempts to match an iterable entity data definition with a
   * given component shape plugin at a specified level. It delegates the actual
   * matching logic to the matchScalar method.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition to match.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin to match against.
   * @param int $level
   *   The current level of matching.
   * @param array $parentDefinitions
   *   (optional) An array of parent definitions for context. Defaults to an
   *   empty array.
   *
   * @return mixed
   *   The result of the matchScalar method.
   */
  private function matchIterable(EntityDataDefinitionInterface $entityDataDefinition, ComponentShapePluginInterface $shape, int $level, array $parentDefinitions = []) {
    return $this->matchScalar($entityDataDefinition, $shape, $level, $parentDefinitions);
  }

  /**
   * Matches scalar fields between an entity data definition and a shape.
   *
   * This method attempts to find matching scalar fields between the provided
   * entity data definition and the shape. It considers various conditions such
   * as whether the field is required, allowed, and supported by the shape.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition to match against.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape plugin to match fields with.
   * @param int $level
   *   The level of recursion allowed for matching nested fields.
   * @param array $parentDefinitions
   *   (optional) An array of parent field definitions for nested matching.
   *
   * @return array
   *   An array of matched fields with their respective properties such as
   *   title, group, definition, and weight.
   */
  private function matchScalar(EntityDataDefinitionInterface $entityDataDefinition, ComponentShapePluginInterface $shape, int $level, array $parentDefinitions = []) {
    $matches = [];

    $isRequired = $shape->isRequired();
    $shapeFieldDefinition = $shape->getFieldItemList()->getFieldDefinition();
    $fieldDefinitions = $this->dataDefinitions($entityDataDefinition);
    $fieldDefinitions += $this->entityDefinitions($entityDataDefinition, $shape);

    // $matches += $this->matchEntity($entityDataDefinition, $shape, $level, $parentDefinitions);$entityType = $this->entityTypeManager->getDefinition($entityDataDefinition->getEntityTypeId());
    // if ($entityType->hasLinkTemplate('canonical')) {
    //   $fieldDefinition = BaseFieldDefinition::create('link')
    //     ->setLabel(t('@label Canonical Link', [
    //       '@label' => $entityDataDefinition->getLabel(),
    //     ]))
    //     ->setName('entity')
    //     ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
    //     ->setRequired($isRequired);
    //   $parentFieldDefinitions = array_merge($parentDefinitions, [$fieldDefinition]);
    //   $matches[$this->key($parentFieldDefinitions, '_link_canonical')] = [
    //     'title' => $this->label($parentFieldDefinitions),
    //     'group' => $this->group($parentFieldDefinitions),
    //     'definition' => $fieldDefinition,
    //     'weight' => $level,
    //   ];
    // }

    foreach ($fieldDefinitions as $fieldDefinition) {
      assert($fieldDefinition instanceof FieldDefinitionInterface);
      if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
        continue;
      }
      if ($isRequired && !$fieldDefinition->isRequired()) {
        continue;
      }
      $parentFieldDefinitions = array_merge($parentDefinitions, [$fieldDefinition]);

      // If shape field is required, but the field definition is not, skip.
      if ($shapeFieldDefinition->isRequired() && !$fieldDefinition->isRequired()) {
        continue;
      }

      // Check if the field definition is allowed.
      if (!$shape->allowFieldDefinition($fieldDefinition)) {
        continue;
      }

      // Check if the field definition is supported.
      if ($shape->supportsFieldDefinition($fieldDefinition)) {
        $matches[$this->key($parentFieldDefinitions)] = [
          'title' => $this->label($parentFieldDefinitions),
          'group' => $this->group($parentFieldDefinitions),
          'definition' => $fieldDefinition,
          'weight' => $level,
        ];
        continue;
      }

      $properties = $this->dataDefinitions($fieldDefinition);
      // Check if all field properties are supported.
      if ($shape->supportsFieldProperties($properties)) {
        $matches[$this->key($parentFieldDefinitions)] = [
          'title' => $this->label($parentFieldDefinitions),
          'group' => $this->group($parentFieldDefinitions),
          'definition' => $fieldDefinition,
          'weight' => $level,
        ];
        continue;
      }
      foreach ($properties as $propertyName => $property) {
        $isReference = $this->isReference($property);
        if ($isReference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        if ($isReference) {
          if ($level === 0) {
            continue;
          }
          if ($property instanceof DataReferenceDefinitionInterface && is_a($property->getClass(), EntityReference::class, TRUE)) {
            $target = $property->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            $matches += $this->match($target, $shape, $level - 1, $parentFieldDefinitions);
          }
        }
        // Check if single field property is supported.
        elseif ($shape->supportsFieldProperty($property)) {
          $matches[$this->key($parentFieldDefinitions, $propertyName)] = [
            'title' => $this->label($parentFieldDefinitions, $property->getLabel()),
            'group' => $this->group($parentFieldDefinitions),
            'definition' => $fieldDefinition,
            'weight' => $level,
          ];
        }
      }
    }

    return $matches;
  }

  /**
   * Get dynamic field definitions for an entity.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition.
   * @param ComponentShapePluginInterface $shape
   *   The component shape plugin.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   An array of field definitions.
   */
  private function entityDefinitions(EntityDataDefinitionInterface $entityDataDefinition, ComponentShapePluginInterface $shape): array {
    $isRequired = $shape->isRequired();
    $fieldDefinitions = [];

    $entityType = $this->entityTypeManager->getDefinition($entityDataDefinition->getEntityTypeId());
    if ($entityType->hasKey('label')) {
      $fieldName = '_entity:label';
      $fieldDefinitions[] = BaseFieldDefinition::create('string')
        ->setLabel(t('(@label) Label', [
          '@label' => $entityDataDefinition->getLabel(),
        ]))
        ->setName($fieldName)
        ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
        ->setRequired($isRequired);
    }
    foreach ($entityType->getLinkTemplates() as $templateId => $template) {
      $fieldName = '_entity:link:' . $templateId;
      $fieldDefinitions[$fieldName] = BaseFieldDefinition::create('link')
        ->setLabel(t('(@label) @link Link', [
          '@label' => $entityDataDefinition->getLabel(),
          '@link' => ucwords(str_replace(['-', '_', '.'], ' ', $templateId)),
        ]))
        ->setName($fieldName)
        ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
        ->setRequired($isRequired);
    }
    // if ($entityType->hasLinkTemplate('canonical')) {
    //   $fieldName = '_entity:link_canonical';
    //   $fieldDefinitions[$fieldName] = BaseFieldDefinition::create('link')
    //     ->setLabel(t('(@label) Canonical Link', [
    //       '@label' => $entityDataDefinition->getLabel(),
    //     ]))
    //     ->setName($fieldName)
    //     ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
    //     ->setRequired($isRequired);
    // }
    // if ($entityType->hasLinkTemplate('edit-form')) {
    //   $fieldName = '_entity:link_canonical';
    //   $fieldDefinitions[$fieldName] = BaseFieldDefinition::create('link')
    //     ->setLabel(t('(@label) Canonical Link', [
    //       '@label' => $entityDataDefinition->getLabel(),
    //     ]))
    //     ->setName($fieldName)
    //     ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
    //     ->setRequired($isRequired);
    // }
    return $fieldDefinitions;
  }

  /**
   * Provides data definitions based on the type of DataDefinitionInterface.
   *
   * This method uses a match expression to handle different types of data
   * definitions:
   * - EntityDataDefinitionInterface: Handles entity level data definitions.
   * - FieldDefinitionInterface: Recursively handles field level data
   *   definitions.
   * - FieldItemDataDefinitionInterface: Handles field item data definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $dd
   *   The data definition to process.
   *
   * @return array
   *   An array of data definitions based on the type of the provided data
   *   definition.
   *
   * @throws \LogicException
   *   Thrown when an unhandled data definition type is encountered.
   */
  private function dataDefinitions(DataDefinitionInterface $dd): array {
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
   * Recursively matches data definitions and returns field definitions.
   *
   * This method inspects the provided DataDefinitionInterface instance and
   * determines the appropriate field definitions based on the type of data
   * definition. It handles entity-level and field-level data definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $dd
   *   The data definition to inspect.
   *
   * @return array
   *   An array of field definitions.
   *
   * @throws \LogicException
   *   Thrown when an unhandled data definition type is encountered.
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
   * Determines if the given data definition or typed data is a reference.
   *
   * This method checks if the provided data definition or typed data is a
   * reference type. It throws an exception if the provided typed data is not a
   * leaf node.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface|\Drupal\Core\TypedData\DataDefinitionInterface $td_or_dd
   *   The data definition or typed data to check.
   *
   * @return bool|null
   *   TRUE if the data definition is a reference, FALSE if it is a primitive
   *   type, or NULL if the type cannot be handled and merits logging.
   *
   * @throws \LogicException
   *   Thrown when the provided typed data is not a leaf node.
   */
  private function isReference(TypedDataInterface|DataDefinitionInterface $td_or_dd): ?bool {
    if ($td_or_dd instanceof TypedDataInterface && !$td_or_dd->getParent() instanceof FieldItemInterface) {
      throw new \LogicException(__METHOD__ . ' was given a non-leaf.');
    }
    $dd = $td_or_dd instanceof TypedDataInterface ? $td_or_dd->getDataDefinition() : $td_or_dd;
    return match(TRUE) {
      $dd instanceof DataReferenceDefinitionInterface => TRUE,
      is_a($dd->getClass(), PrimitiveInterface::class, TRUE) => FALSE,
      // Anything else cannot be handled and merits logging.
      TRUE => NULL,
    };
  }

  /**
   * Get the label for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   * @param string|null $propertyLabel
   *   The property label.
   *
   * @return string
   *   The key.
   */
  private function label(array $definitions, $propertyLabel = NULL): string {
    $definition = end($definitions);
    return $definition->getLabel() . ($propertyLabel ? ": $propertyLabel" : '') . ' (' . $definition->getName() . ')';
  }

  /**
   * Get the group for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   *
   * @return string
   *   The key.
   */
  private function group(array $definitions): string {
    $first = array_shift($definitions);
    $group = [
      ucwords(str_replace('_', ' ', $first->getTargetEntityTypeId())),
    ];
    if (!empty($definitions)) {
      $group[0] .= ' (' . $first->getName() . ')';
      // $group[] = $first->getName();
      foreach ($definitions as $definition) {
        $group[] = ucwords(str_replace('_', ' ', $definition->getTargetEntityTypeId()));
      }
    }
    return implode(' → ', $group);
  }

  /**
   * Get the key for a set of definitions.
   *
   * @param Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $definitions
   *   The definitions.
   * @param string|null $propertyName
   *   The property name.
   *
   * @return string
   *   The key.
   */
  private function key(array $definitions, $propertyName = NULL): string {
    $key = implode('.', array_map(fn ($d) => $d->getName(), $definitions));
    if ($propertyName) {
      $key .= ':' . $propertyName;
    }
    return $key;
  }

}
