<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTrait;

/**
 * Provides methods for matching fields between content entities and shapes.
 */
final class MatcherField extends MatcherBase {

  use StringTranslationTrait;
  use IconTrait;

  /**
   * The maximum number of levels to match.
   *
   * @var int
   */
  protected $maxLevels = 2;

  /**
   * Field types never offered as a component prop source.
   *
   * Matching works on DATA TYPES, which are deliberately coarse: a password
   * field's `value` property is a plain `string`, indistinguishable from a
   * title. Without this list every string-accepting prop is offered the
   * user's password hash as content — and entity types routinely reference a
   * user, so it shows up on ordinary components, not just user-targeted ones.
   *
   * Kept to genuinely sensitive types on purpose. Identifiers (id, uuid,
   * target_id) and system fields (langcode, timezone, roles) are still
   * offered: they are noisy rather than dangerous, and excluding them could
   * remove matches components legitimately rely on.
   *
   * This filters the OFFER list only. Resolution (::getEntityValue(),
   * ::getEntityField()) is untouched, so a component already configured
   * against an excluded field keeps working and nothing silently blanks.
   */
  protected const EXCLUDED_FIELD_TYPES = [
    'password',
  ];

  /**
   * Retrieves the field definition for a given key from the component shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin instance.
   * @param string $key
   *   The key for which the field definition is to be retrieved.
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   * @param string|null $fieldType
   *   (optional) The field type to match against. Defaults to NULL.
   * @param bool $all
   *   (optional) Whether to match all fields. Defaults to FALSE.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition if found, or NULL if no matching definition exists.
   */
  public function getFieldDefinition(
    ComponentShapePluginInterface $shape,
    string $key,
    ?string $entityTypeId = NULL,
    ?string $entityBundle = NULL,
    ?string $fieldType = NULL,
    bool $all = FALSE,
  ): ?FieldDefinitionInterface {
    $matches = $this->getMatches($shape, $entityTypeId, $entityBundle, $fieldType, $all);
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
   * @param array $properties
   *   (optional) An associative array of properties to retrieve from the field.
   * @param mixed $default
   *   (optional) The default value to return if the field does not exist.
   * @param bool $published
   *   (optional) Whether to only return values from published entities.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return mixed
   *   The value of the specified field.
   */
  public function getEntityValue(
    ContentEntityInterface $entity,
    string $key,
    array $properties = [],
    mixed $default = [],
    ?bool $published = TRUE,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): mixed {
    $path = explode('.', $key);
    $finalKey = array_pop($path);
    [$fieldName, $property, $subProperty] = explode(':', $finalKey . '::');
    // If we are a dynamic entity field.
    if ($fieldName === '_entity') {
      // Get the final entity.
      $finalEntity = $this->getEntity($entity, $key, $published, $cacheableMetadata);
      if ($finalEntity) {
        return $this->getDynamicEntityValues($finalEntity, $property, $subProperty);
      }
      return NULL;
    }
    if ($fieldName === '_field') {
      // Get the final field.
      $finalField = $this->getEntityField($entity, $key, $published, $cacheableMetadata);
      if ($finalField) {
        return $this->getDynamicFieldValues($finalField, $subProperty);
      }
      return NULL;
    }
    // Get the field.
    $field = $this->getEntityField($entity, $key, $published, $cacheableMetadata);

    if ($field) {
      $value = $field->getValue();
      if ($property) {
        $value = $value[0][$property] ?? $default;
      }
      if ($subProperty) {
        $parts = explode('~', $subProperty);
        $value = NestedArray::getValue($value, $parts);
      }
      if (is_array($value)) {
        if ($properties) {
          $v = [];
          foreach ($value as $delta => $val) {
            foreach ($properties as $name => $prop) {
              $v[$delta][$name] = $val[$prop] ?? NULL;
              // Special handling for uri fields to support options.
              if ($prop === 'uri' && !empty($val['options']) && in_array($field->getFieldDefinition()->getType(), [
                'uri',
                'link',
              ])) {
                $v[$delta][$name] = [
                  'uri' => $v[$delta][$name],
                  'options' => $val['options'] ?? [],
                ];
              }
              if (!$v[$delta][$name] && $prop === 'target_id' && !empty($val['entity'])) {
                // Support passing entities directly.
                $v[$delta][$name]['entity'] = $val['entity'];
              }
            }
          }
          $value = $v;
        }
      }
      return $value;
    }
    return $default;
  }

  /**
   * Retrieves the value of a field from a content entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity from which to retrieve the field value.
   * @param string $key
   *   The dot-separated string representing the field path.
   * @param bool $published
   *   (optional) Whether to only return values from published entities.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity for the specified field, or NULL if the field does not exist.
   */
  public function getEntity(
    ContentEntityInterface $entity,
    string $key,
    ?bool $published = TRUE,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): ?ContentEntityInterface {
    $path = explode('.', $key);
    return $this->recurseEntity($entity, $path, $published, $cacheableMetadata);
  }

  /**
   * Recursively retrieves the value of a field from a content entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity from which to retrieve the field value.
   * @param array $path
   *   An array representing the path to the field. Each element in the array
   *   should be a string in the format 'field_name:property', where 'property'
   *   is optional.
   * @param bool $published
   *   (optional) Whether to only return values from published entities.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The field item list for the specified field, or NULL if the field does
   *   not exist.
   */
  private function recurseEntity(
    EntityInterface $entity,
    array $path,
    ?bool $published = TRUE,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): ?ContentEntityInterface {
    if ($cacheableMetadata) {
      $cacheableMetadata->addCacheableDependency($entity);
    }
    $key = array_shift($path);
    [$fieldName] = explode(':', $key . '::');
    $this->moduleHandler->invokeAll('neo_alchemist_entity_load', [$entity]);
    $this->moduleHandler->invokeAll('neo_alchemist_' . $entity->getEntityTypeId() . '_load', [$entity]);
    if ($entity instanceof ContentEntityInterface) {
      if ($published) {
        $entityType = $entity->getEntityType();
        if ($entityType->hasKey('status') || $entityType->hasKey('published')) {
          $key = $entityType->getKey('status') ?: $entityType->getKey('published');
          if (empty($entity->get($key)->value)) {
            return NULL;
          }
        }
      }
      // If field name is _entity, we return the current entity as the current
      // entity is what we want.
      if (in_array($fieldName, ['_entity', '_field'])) {
        return $entity;
      }
      if (!$entity->hasField($fieldName)) {
        return NULL;
      }
      $field = $entity->get($fieldName);
      if ($field instanceof EntityReferenceFieldItemListInterface && !empty($path)) {
        if (!$field->entity) {
          return NULL;
        }
        return $this->recurseEntity($field->entity, $path, $published, $cacheableMetadata);
      }
      return $entity;
    }
    return NULL;
  }

  /**
   * Retrieves the value of a field from a content entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity from which to retrieve the field value.
   * @param string $key
   *   The dot-separated string representing the field path.
   * @param bool $published
   *   (optional) Whether to only return values from published entities.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|null
   *   The field item list for the specified field, or NULL if the field does
   *   not exist.
   */
  public function getEntityField(
    ContentEntityInterface $entity,
    string $key,
    ?bool $published = TRUE,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): ?FieldItemListInterface {
    $finalEntity = $this->getEntity($entity, $key, $published, $cacheableMetadata);
    if ($finalEntity) {
      $path = explode('.', $key);
      $key = end($path);
      [$fieldName, $property, $subProperty] = explode(':', $key . '::');

      if ($fieldName === '_field') {
        // Use property as field name when acting on dynamic field.
        $fieldName = $property;
      }
      if (!$finalEntity->hasField($fieldName)) {
        return NULL;
      }
      return $finalEntity->get($fieldName);
    }
    return NULL;
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
   * @param string|null $fieldType
   *   (optional) The field type to match against. Defaults to NULL.
   * @param bool $all
   *   (optional) Whether to match all fields. Defaults to FALSE.
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
  public function getMatchesAsOptions(
    ComponentShapePluginInterface $shape,
    ?string $entityTypeId = NULL,
    ?string $entityBundle = NULL,
    ?string $fieldType = NULL,
    bool $all = FALSE,
  ): array {
    $options = [];
    $matches = $this->getMatches($shape, $entityTypeId, $entityBundle, $fieldType, $all);
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
   * @param string|null $fieldType
   *   (optional) The field type to match against. Defaults to NULL.
   * @param bool $all
   *   (optional) Whether to match all fields. Defaults to FALSE.
   *
   * @return array
   *   An array of matches, sorted by weight and title.
   */
  public function getMatches(
    ComponentShapePluginInterface $shape,
    ?string $entityTypeId = NULL,
    ?string $entityBundle = NULL,
    ?string $fieldType = NULL,
    bool $all = FALSE,
  ): array {
    $matches = [];
    if ($entityTypeId) {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      if (!$entityType->getKey('bundle')) {
        $entityBundle = $entityTypeId;
      }
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
        $shape->getTargetEntityBundle() ?? $entityType,
      ]));
      $entityDataDefinition = EntityDataDefinition::createFromDataType($dataType);
    }
    else {
      return $matches;
    }

    if ($all) {
      $matches = $this->matchAll($entityDataDefinition, 1);
    }
    else {
      $matches = $this->match($entityDataDefinition, $shape, $this->maxLevels);
    }

    if ($fieldType) {
      $matches = array_filter($matches, function ($match) use ($fieldType) {
        return $match['definition']->getType() === $fieldType;
      });
    }

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
  private function match(
    EntityDataDefinitionInterface $entityDataDefinition,
    ComponentShapePluginInterface $shape,
    int $level,
    array $parentDefinitions = [],
  ): array {
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
  private function matchScalar(
    EntityDataDefinitionInterface $entityDataDefinition,
    ComponentShapePluginInterface $shape,
    int $level,
    array $parentDefinitions = [],
  ) {
    $matches = [];

    $requireRequired = $shape->isRequired() || $shape->getFieldItemList()->getFieldDefinition()->isRequired();
    $fieldDefinitions = $this->dataDefinitions($entityDataDefinition);
    $fieldDefinitions += $this->dynamicFieldDefinitions($fieldDefinitions, $shape);
    $fieldDefinitions += $this->dynamicEntityDefinitions($entityDataDefinition, $shape);

    foreach ($fieldDefinitions as $fieldDefinition) {
      assert($fieldDefinition instanceof FieldDefinitionInterface);
      if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
        continue;
      }
      // Never offer a sensitive field as a content source, whatever the
      // shape accepts. This runs on every recursion level, so it covers
      // referenced entities too (e.g. an entity's author's password).
      if (in_array($fieldDefinition->getType(), static::EXCLUDED_FIELD_TYPES, TRUE)) {
        continue;
      }
      // Skip non-required fields when the shape requires a value.
      if ($requireRequired && !$fieldDefinition->isRequired()) {
        continue;
      }
      if (!$shape->allowFieldDefinition($fieldDefinition)) {
        continue;
      }

      $parentFieldDefinitions = [...$parentDefinitions, $fieldDefinition];

      if ($shape->supportsFieldDefinition($fieldDefinition)) {
        $matches[$this->key($parentFieldDefinitions)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level);
        continue;
      }

      $properties = $this->dataDefinitions($fieldDefinition);

      if ($shape->supportsFieldProperties($fieldDefinition, $properties)) {
        $matches[$this->key($parentFieldDefinitions)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level);
        continue;
      }

      foreach ($properties as $propertyName => $property) {
        $isReference = $this->isReference($property);
        if ($isReference === NULL) {
          continue;
        }
        if ($isReference) {
          if ($level > 0 && $property instanceof DataReferenceDefinitionInterface && is_a($property->getClass(), EntityReference::class, TRUE)) {
            $target = $property->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            $matches += $this->match($target, $shape, $level - 1, $parentFieldDefinitions);
          }
          continue;
        }
        if ($shape->supportsFieldProperty($fieldDefinition, $property)) {
          $matches[$this->key($parentFieldDefinitions, $propertyName)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level, (string) $property->getLabel());
        }
      }

      foreach ($shape->getMatches($fieldDefinition) as $match => $label) {
        $matches[$this->key($parentFieldDefinitions, $match)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level, $label);
      }
    }

    return $matches;
  }

  /**
   * Builds a match entry array.
   *
   * @param array $parentDefinitions
   *   The parent field definitions.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   * @param int $weight
   *   The weight for sorting.
   * @param string|null $propertyLabel
   *   (optional) A property label suffix.
   *
   * @return array
   *   The match entry.
   */
  private function buildMatchEntry(array $parentDefinitions, FieldDefinitionInterface $fieldDefinition, int $weight, ?string $propertyLabel = NULL): array {
    return [
      'title' => $this->label($parentDefinitions, $propertyLabel),
      'group' => $this->group($parentDefinitions),
      'definition' => $fieldDefinition,
      'weight' => $weight,
    ];
  }

  /**
   * Get all nested fields within an entity defintition.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition to match.
   * @param int $level
   *   The current level of matching.
   * @param array $parentDefinitions
   *   (optional) An array of parent definitions for context. Defaults to an
   *   empty array.
   *
   * @return array
   *   An array of matched definitions.
   */
  private function matchAll(
    EntityDataDefinitionInterface $entityDataDefinition,
    int $level,
    array $parentDefinitions = [],
  ): array {
    $matches = [];
    $fieldDefinitions = $this->dataDefinitions($entityDataDefinition);
    foreach ($fieldDefinitions as $fieldDefinition) {
      assert($fieldDefinition instanceof FieldDefinitionInterface);
      if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
        continue;
      }
      $parentFieldDefinitions = [...$parentDefinitions, $fieldDefinition];
      $matches[$this->key($parentFieldDefinitions)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level);

      $properties = $this->dataDefinitions($fieldDefinition);
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
            $matches += $this->matchAll($target, $level - 1, $parentFieldDefinitions);
          }
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
  private function dynamicEntityDefinitions(
    EntityDataDefinitionInterface $entityDataDefinition,
    ComponentShapePluginInterface $shape,
  ): array {
    $entityDefinitions = [];
    $isRequired = $shape->isRequired();
    $entityTypeId = $entityDataDefinition->getEntityTypeId();
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    $entityLabel = $entityDataDefinition->getLabel();
    $labelPrefix = $entityLabel ? '(' . $entityLabel . ') ' : '';

    if ($entityType->hasKey('label')) {
      $entityDefinitions['_entity:label'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Label'), '_entity:label', $entityTypeId, $isRequired);
    }

    $entityDefinitions['_entity:icon'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Icon'), '_entity:icon', $entityTypeId, $isRequired);

    // Config entity type definitions.
    if ($entityType instanceof ConfigEntityTypeInterface) {
      $entityDefinitions['_entity:label_page'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Label (with System Page to Page conversion)'), '_entity:label_page', $entityTypeId, $isRequired);
      $entityDefinitions['_entity:icon_page'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Icon (with System Page to Page conversion)'), '_entity:icon_page', $entityTypeId, $isRequired);
    }

    // Content entity type definitions.
    if ($entityType instanceof ContentEntityTypeInterface) {
      foreach ($entityType->getLinkTemplates() as $templateId => $template) {
        if (str_starts_with($templateId, 'alchemist.')) {
          continue;
        }
        $fieldName = '_entity:link:' . $templateId;
        $label = '(' . $this->t('Link') . ') ' . ucwords(str_replace(['-', '_', '.'], ' ', $templateId));
        $entityDefinitions[$fieldName] = $this->createDynamicDefinition('link', $label, $fieldName, $entityTypeId, $isRequired);
      }
    }

    return $entityDefinitions;
  }

  /**
   * Creates a dynamic BaseFieldDefinition.
   *
   * @param string $type
   *   The field type.
   * @param string $label
   *   The field label.
   * @param string $name
   *   The field name.
   * @param string $entityTypeId
   *   The target entity type ID.
   * @param bool $required
   *   Whether the field is required.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The base field definition.
   */
  private function createDynamicDefinition(string $type, string $label, string $name, string $entityTypeId, bool $required): BaseFieldDefinition {
    return BaseFieldDefinition::create($type)
      ->setLabel($label)
      ->setName($name)
      ->setTargetEntityTypeId($entityTypeId)
      ->setRequired($required);
  }

  /**
   * Retrieves dynamic field definitions based on the component shape.
   *
   * This method checks if the shape is of type 'boolean' and processes the
   * field definitions accordingly. It currently does not implement any logic
   * for boolean shapes but can be extended in the future.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $fieldDefinitions
   *   An array of field definitions to process.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The component shape plugin interface instance.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   An array of dynamic field definitions.
   */
  protected function dynamicFieldDefinitions(
    array $fieldDefinitions,
    ComponentShapePluginInterface $shape,
  ): array {
    $definitions = [];
    $isRequired = $shape->isRequired();
    if ($shape->getType() === 'boolean') {
      foreach ($fieldDefinitions as $fieldDefinition) {
        if ($fieldDefinition->getType() === 'boolean') {
          continue;
        }
        if ($isRequired && !$fieldDefinition->isRequired()) {
          continue;
        }
        $fieldName = '_field:' . $fieldDefinition->getName() . ':empty';
        $label = $fieldDefinition->getLabel() . ' (Is Empty)';
        $definitions[$fieldName] = BaseFieldDefinition::create('boolean')
          ->setLabel($label)
          ->setName($fieldName)
          ->setTargetEntityTypeId($fieldDefinition->getTargetEntityTypeId())
          ->setRequired($fieldDefinition->isRequired());

        $fieldName = '_field:' . $fieldDefinition->getName() . ':not_empty';
        $label = $fieldDefinition->getLabel() . ' (Is Not Empty)';
        $definitions[$fieldName] = BaseFieldDefinition::create('boolean')
          ->setLabel($label)
          ->setName($fieldName)
          ->setTargetEntityTypeId($fieldDefinition->getTargetEntityTypeId())
          ->setRequired($fieldDefinition->isRequired());
      }
    }
    return $definitions;
  }

  /**
   * Retrieves the value of a specified entity definition property.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity from which to retrieve the property value.
   * @param string $property
   *   The property to retrieve.
   * @param string|null $subProperty
   *   (optional) The sub-property to retrieve.
   *
   * @return array
   *   The value of the specified entity definition property.
   */
  private function getDynamicEntityValues(
    EntityInterface $entity,
    string $property,
    ?string $subProperty = NULL,
  ): array {
    return match ($property) {
      'label' => [$entity->label()],
      'label_page' => [$entity->id() === 'system' ? 'Page' : $entity->label()],
      'icon' => (function () use ($entity) {
        $icon = neo_icon_entity($entity)->getIcon();
        return [$icon?->getName() ?? ''];
      })(),
      'icon_page' => (function () use ($entity) {
        $labelOverride = $entity->id() === 'system' ? 'Page' : NULL;
        $icon = neo_icon_entity($entity, $labelOverride)->getIcon();
        return [$icon?->getName() ?? ''];
      })(),
      'link' => $this->getEntityDefinitionLink($entity, $subProperty),
      default => [],
    };
  }

  /**
   * Retrieves the dynamic field values for a specific field and type.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field item list interface instance.
   * @param string|null $type
   *   The type of dynamic field values to retrieve.
   *
   * @return array
   *   An array of dynamic field values.
   */
  private function getDynamicFieldValues(
    FieldItemListInterface $field,
    string $type,
  ): array {
    return match ($type) {
      'empty' => [$field->isEmpty()],
      'not_empty' => [!$field->isEmpty()],
      default => $field->getValue(),
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
  private function getEntityDefinitionLink(
    ContentEntityInterface $entity,
    string $property,
  ): array {
    $url = $entity->isNew() ? Url::fromRoute('<front>') : $entity->toUrl($property);
    if (!$url || !$url->access()) {
      return [];
    }
    $titleReplacements = ['-', '_', '.'];
    $route = \Drupal::service('router.route_provider')->getRouteByName($url->getRouteName());
    $title = match($property) {
      'canonical' => $route->getDefault('_title') ?? $entity->label(),
      default => $route->getDefault('_title') ?? ucwords(str_replace($titleReplacements, ' ', str_replace('-form', '', $property))),
    };
    $options = [];
    if ($icon = $this->adminIcon($title)->getIcon()) {
      $options['attributes']['data-icon'] = $icon->getName();
    }
    return [
      'title' => $title,
      'uri' => $url->toUriString(),
      'options' => $options,
    ];
  }

}
