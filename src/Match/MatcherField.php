<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Match;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
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
   * Constructs a MatcherField object.
   *
   * Extends MatcherBase's dependencies with the route provider, which only
   * this matcher needs — it titles entity operation links from the route's
   * own _title default.
   */
  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected readonly RouteProviderInterface $routeProvider,
  ) {
    parent::__construct($moduleHandler, $entityTypeManager, $entityFieldManager, $entityTypeBundleInfo);
  }

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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
        return $this->getDynamicEntityValues(
          $finalEntity,
          $property,
          $subProperty,
          $cacheableMetadata,
        );
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
      [$fieldName, $property] = explode(':', $key . '::');

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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
    $entityDataDefinition = $this->rootDefinition($shape, $entityTypeId, $entityBundle);
    if (!$entityDataDefinition) {
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
   * The starting entity data definition for a match walk.
   *
   * Mirrors the override contract of ::getMatches(): an explicit entity type
   * takes its bundle from the override only, and with no override the shape's
   * target entity type and bundle decide.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The component shape plugin.
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   *
   * @return \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null
   *   The definition, or NULL when neither an override nor the shape names an
   *   entity type.
   */
  public function resolveTarget(ComponentShapePluginInterface $shape, ?string $entityTypeId = NULL, ?string $entityBundle = NULL): ?EntityDataDefinitionInterface {
    return $this->rootDefinition($shape, $entityTypeId, $entityBundle);
  }

  /**
   * Resolves the entity type and bundle a match runs against.
   *
   * An entity type override takes its bundle from the override too, a NULL
   * there meaning "every bundle"; a bundle passed without an entity type is
   * ignored. Callers that need to key something off the outcome — a cache id,
   * say — must derive it from here rather than replay the decision, or the two
   * drift and answers get filed under the wrong key.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape being matched.
   * @param string|null $entityTypeId
   *   (optional) The entity type to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   *
   * @return \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null
   *   The definition, or NULL when neither an override nor the shape names an
   *   entity type.
   */
  private function rootDefinition(ComponentShapePluginInterface $shape, ?string $entityTypeId = NULL, ?string $entityBundle = NULL): ?EntityDataDefinitionInterface {
    if ($entityTypeId) {
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      if (!$entityType->getKey('bundle')) {
        $entityBundle = $entityTypeId;
      }
      return EntityDataDefinition::createFromDataType(implode(':', array_filter([
        'entity',
        $entityTypeId,
        $entityBundle,
      ])));
    }
    if ($entityType = $shape->getTargetEntityType()) {
      return EntityDataDefinition::createFromDataType(implode(':', array_filter([
        'entity',
        $entityType,
        $shape->getTargetEntityBundle() ?? $entityType,
      ])));
    }
    return NULL;
  }

  /**
   * The most reference hops a node address may take.
   *
   * The browser lets a site builder descend one pane at a time, and a cyclic
   * reference (a taxonomy parent, a node referencing nodes) makes the tree
   * infinitely deep in principle. Eight hops is far past any sane binding and
   * exists so a crafted key cannot make ::getNodeMatches() walk forever.
   */
  private const MAX_HOPS = 8;

  /**
   * The matches and reference doorways at one node of the entity tree.
   *
   * ::getMatches() walks the whole tree to ::$maxLevels and flattens it, which
   * global search needs but a column browser does not: a pane shows one entity
   * level. This computes exactly that — the fields at the node a hop path
   * reaches, plus the references leading out of it — so its cost is bounded by
   * one entity's field list no matter how referenced the site's content model
   * is, and a pane can sit deeper than ::$maxLevels ever walks.
   *
   * The hop predicate is ::matchScalar()'s recursion branch, verbatim: a field
   * descends only if it passed the same gates (exclusion list, requiredness,
   * ::allowFieldDefinition()) and was NOT already claimed as a leaf — so a key
   * assembled from these panes is a key the eager walk would also have built,
   * had it walked deep enough. In `$all` mode the gates relax exactly as
   * ::matchAll()'s do.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The component shape plugin.
   * @param string[] $hops
   *   Reference field names leading to the node, [] for the root entity.
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   * @param bool $all
   *   (optional) Whether to match all fields. Defaults to FALSE.
   *
   * @return array|null
   *   NULL when the path does not resolve, or:
   *   - 'entity': the node's entity type label;
   *   - 'crumbs': one ['label', 'entity'] per level, root first — the root is
   *     labelled by its entity type, each hop by the field that took it;
   *   - 'leaves': matches at the node, as ::getMatches() would key and title
   *     them;
   *   - 'refs': the doorways out, as ['segment', 'label', 'target'].
   */
  public function getNodeMatches(ComponentShapePluginInterface $shape, array $hops = [], ?string $entityTypeId = NULL, ?string $entityBundle = NULL, bool $all = FALSE): ?array {
    if (count($hops) > self::MAX_HOPS) {
      return NULL;
    }
    $entityDataDefinition = $this->rootDefinition($shape, $entityTypeId, $entityBundle);
    if (!$entityDataDefinition) {
      return NULL;
    }
    $crumbs = [
      [
        'label' => $this->entityTypeLabel($entityDataDefinition->getEntityTypeId()),
        'entity' => '',
      ],
    ];
    $parentDefinitions = [];
    foreach ($hops as $hop) {
      $fieldDefinition = $this->dataDefinitions($entityDataDefinition)[(string) $hop] ?? NULL;
      if (!$fieldDefinition instanceof FieldDefinitionInterface) {
        return NULL;
      }
      $target = $this->referenceTarget($fieldDefinition, $shape, $all);
      if (!$target) {
        return NULL;
      }
      $entityDataDefinition = $target;
      $parentDefinitions[] = $fieldDefinition;
      $crumbs[] = [
        'label' => (string) $fieldDefinition->getLabel(),
        'entity' => $this->entityTypeLabel($target->getEntityTypeId()),
      ];
    }

    $leaves = $all
      ? $this->matchAll($entityDataDefinition, 0, $parentDefinitions)
      : $this->matchScalar($entityDataDefinition, $shape, 0, $parentDefinitions);

    $refs = [];
    foreach ($this->dataDefinitions($entityDataDefinition) as $name => $fieldDefinition) {
      if (!$fieldDefinition instanceof FieldDefinitionInterface) {
        continue;
      }
      $target = $this->referenceTarget($fieldDefinition, $shape, $all);
      if (!$target) {
        continue;
      }
      $refs[] = [
        'segment' => (string) $name,
        'label' => (string) $fieldDefinition->getLabel(),
        'target' => $this->entityTypeLabel($target->getEntityTypeId()),
      ];
    }

    return [
      'entity' => $this->entityTypeLabel($entityDataDefinition->getEntityTypeId()),
      'crumbs' => $crumbs,
      'leaves' => $leaves,
      'refs' => $refs,
    ];
  }

  /**
   * Where a field's entity reference leads, if the walk would descend it.
   *
   * This is the doorway predicate: NULL means ::matchScalar() (or, with $all,
   * ::matchAll()) would not recurse into this field — because it is excluded,
   * fails the shape's gates, or was already claimed as a leaf — and a hop
   * through it is therefore not a legal address.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The component shape plugin.
   * @param bool $all
   *   Whether every field is on the table rather than only shape-supported
   *   ones.
   *
   * @return \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null
   *   The referenced entity's data definition, or NULL.
   */
  private function referenceTarget(FieldDefinitionInterface $fieldDefinition, ComponentShapePluginInterface $shape, bool $all): ?EntityDataDefinitionInterface {
    if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
      return NULL;
    }
    if (in_array($fieldDefinition->getType(), static::EXCLUDED_FIELD_TYPES, TRUE)) {
      return NULL;
    }
    if (!$all) {
      $requireRequired = $shape->isRequired() || $shape->getFieldItemList()->getFieldDefinition()->isRequired();
      if ($requireRequired && !$fieldDefinition->isRequired()) {
        return NULL;
      }
      if (!$shape->allowFieldDefinition($fieldDefinition)) {
        return NULL;
      }
      // A field the shape consumes whole is a leaf; the walk records it and
      // moves on without descending.
      if ($shape->supportsFieldDefinition($fieldDefinition)) {
        return NULL;
      }
      if ($shape->supportsFieldProperties($fieldDefinition, $this->dataDefinitions($fieldDefinition))) {
        return NULL;
      }
    }
    foreach ($this->dataDefinitions($fieldDefinition) as $property) {
      if (!$this->isReference($property)) {
        continue;
      }
      if ($property instanceof DataReferenceDefinitionInterface && is_a($property->getClass(), EntityReference::class, TRUE)) {
        $target = $property->getTargetDefinition();
        if ($target instanceof EntityDataDefinitionInterface) {
          return $target;
        }
      }
    }
    return NULL;
  }

  /**
   * An entity type id as ::group() spells it ("user_role" → "User Role").
   *
   * @param string|null $entityTypeId
   *   The entity type id.
   *
   * @return string
   *   The label.
   */
  private function entityTypeLabel(?string $entityTypeId): string {
    return ucwords(str_replace('_', ' ', (string) $entityTypeId));
  }

  /**
   * Matches the entity data definition with the component shape plugin.
   *
   * This method determines whether the shape is scalar or iterable and
   * delegates the matching process to the appropriate method.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $entityDataDefinition
   *   The entity data definition to match.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * @param string|\Stringable|null $propertyLabel
   *   (optional) A property label suffix. Shape plugins are documented to
   *   return translatable labels from ::getMatches(), so this accepts any
   *   stringable — ::label() casts it when building the title.
   *
   * @return array
   *   The match entry.
   */
  private function buildMatchEntry(array $parentDefinitions, FieldDefinitionInterface $fieldDefinition, int $weight, string|\Stringable|null $propertyLabel = NULL): array {
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
      // Same exclusion as ::matchScalar(). This arm is the "offer every field"
      // mode used for sort keys and for "render with a field formatter", and
      // without the check it offered the password hash as something to render
      // — which is exactly what EXCLUDED_FIELD_TYPES exists to prevent, and
      // its contract says "whatever the shape accepts".
      if (in_array($fieldDefinition->getType(), static::EXCLUDED_FIELD_TYPES, TRUE)) {
        continue;
      }
      $parentFieldDefinitions = [...$parentDefinitions, $fieldDefinition];
      $matches[$this->key($parentFieldDefinitions)] = $this->buildMatchEntry($parentFieldDefinitions, $fieldDefinition, $level);

      $properties = $this->dataDefinitions($fieldDefinition);
      foreach ($properties as $property) {
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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
      if ($entityType->hasKey('bundle')) {
        $entityDefinitions['_entity:bundle_label'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Type label'), '_entity:bundle_label', $entityTypeId, $isRequired);
        $entityDefinitions['_entity:bundle_label_page'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Type label (with System Page to Page conversion)'), '_entity:bundle_label_page', $entityTypeId, $isRequired);
        $entityDefinitions['_entity:icon_page'] = $this->createDynamicDefinition('string', $labelPrefix . $this->t('Icon (with System Page to Page conversion)'), '_entity:icon_page', $entityTypeId, $isRequired);
      }
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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the value.
   *
   * @return array
   *   The value of the specified entity definition property.
   */
  private function getDynamicEntityValues(
    EntityInterface $entity,
    string $property,
    ?string $subProperty = NULL,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): array {
    return match ($property) {
      'label' => [$entity->label()],
      'label_page' => [$entity->id() === 'system' ? 'Page' : $entity->label()],
      'bundle_label' => [$this->getEntityBundleLabel($entity)],
      'bundle_label_page' => [$this->entityIsSystemPage($entity) ? 'Page' : $this->getEntityBundleLabel($entity)],
      'icon' => (function () use ($entity) {
        $icon = neo_icon_entity($entity)->getIcon();
        return [$icon?->getName() ?? ''];
      })(),
      'icon_page' => (function () use ($entity) {
        $labelOverride = $entity->id() === 'system' || $this->entityIsSystemPage($entity) ? 'Page' : NULL;
        $icon = neo_icon_entity($entity, $labelOverride)->getIcon();
        return [$icon?->getName() ?? ''];
      })(),
      'link' => $this->getEntityDefinitionLink(
        $entity,
        $subProperty,
        $cacheableMetadata,
      ),
      default => [],
    };
  }

  /**
   * Gets a content entity's bundle label from bundle info.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The bundle label, falling back to the bundle id.
   */
  private function getEntityBundleLabel(EntityInterface $entity): string {
    $info = $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId());
    return (string) ($info[$entity->bundle()]['label'] ?? $entity->bundle());
  }

  /**
   * Whether a content entity is a "system" page bundle.
   *
   * System bundles are an admin-facing concept ("structural page you should
   * not casually delete"); visitor-facing output converts them to "Page" —
   * the same convention the config-entity label_page/icon_page tokens apply.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE for content entities in a "system" bundle.
   */
  private function entityIsSystemPage(EntityInterface $entity): bool {
    return $entity instanceof ContentEntityInterface && $entity->bundle() === 'system';
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
   * A denied `canonical` is reported rather than erased: the value comes back
   * with `access` FALSE so a component can still render the item, unlinked —
   * the same contract UrlShapeTrait::getFieldItemValue() gives every link
   * field, and the one every `{% if x and x.access %}` template is written
   * for. Erasing it instead removed the item wholesale, because
   * ChildrenMatchMapper drops a row whose every mapped shape came back empty.
   *
   * Every other link template still erases on denial. Their URI names an admin
   * route the visitor may not act on, so putting it in the markup would leak a
   * path and the entity's existence for nothing anyone could use.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity for which the link is being generated.
   * @param string $property
   *   The property of the entity for which the URL is generated
   *   (e.g., 'canonical').
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach the access result to. Without
   *   it the decision below is baked into a render cache with no context to
   *   vary on, and one audience's answer is served to another.
   *
   * @return array
   *   An associative array containing:
   *   - 'title': The title of the link.
   *   - 'uri': The URI string of the link.
   *   - 'options': An empty array for additional options (currently unused).
   *   - 'access': Whether the visitor may follow the link.
   *   Empty when there is no URL, or when a non-canonical link is denied.
   */
  private function getEntityDefinitionLink(
    ContentEntityInterface $entity,
    string $property,
    ?CacheableMetadata $cacheableMetadata = NULL,
  ): array {
    $url = $entity->isNew() ? Url::fromRoute('<front>') : $entity->toUrl($property);
    if (!$url) {
      return [];
    }
    $access = $url->access(NULL, TRUE);
    $cacheableMetadata?->addCacheableDependency($access);
    if ($property !== 'canonical' && !$access->isAllowed()) {
      return [];
    }
    $titleReplacements = ['-', '_', '.'];
    $route = $this->routeProvider->getRouteByName($url->getRouteName());
    $title = match($property) {
      // A canonical link means "this entity" — its label, never the route's
      // static _title (taxonomy's canonical route hard-codes _title
      // 'Taxonomy term', which would title every term link identically).
      'canonical' => $entity->label() ?? $route->getDefault('_title'),
      default => $route->getDefault('_title') ?? ucwords(str_replace($titleReplacements, ' ', str_replace('-form', '', $property))),
    };
    $options = [];
    if ($icon = $this->adminIcon($title)->getIcon()) {
      $options['attributes']['data-icon'] = $icon->getName();
    }
    // `title` stays first. HeadingValue::getEntityFieldValue() collapses a
    // match with `while (is_array($value)) $value = reset($value)`, so an
    // `access` key ahead of it would resolve every bound heading to ''.
    return [
      'title' => $title,
      'uri' => $url->toUriString(),
      'options' => $options,
      'access' => $access->isAllowed(),
    ];
  }

}
