<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
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

/**
 * Provides methods for matching fields between content entities and shapes.
 */
final class MatcherField extends MatcherBase {

  use StringTranslationTrait;

  /**
   * The maximum number of levels to match.
   *
   * @var int
   */
  protected $maxLevels = 2;

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
   * @param array $properties
   *   (optional) An associative array of properties to retrieve from the field.
   * @param mixed $default
   *   (optional) The default value to return if the field does not exist.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return mixed
   *   The value of the specified field.
   */
  public function getEntityValue(ContentEntityInterface $entity, string $key, array $properties = [], $default = [], CacheableMetadata $cacheableMetadata = NULL): mixed {
    $path = explode('.', $key);
    return $this->recurseEntity($entity, $path, $properties, $default, $cacheableMetadata);
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
   * @param array $properties
   *   (optional) An associative array of properties to retrieve from the field.
   * @param mixed $default
   *   (optional) The default value to return if the field does not exist.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   (optional) Cacheable metadata to attach to the field value.
   *
   * @return mixed
   *   The value of the field or property, or an empty array if the field or
   *   property does not exist or is empty.
   */
  private function recurseEntity(ContentEntityInterface $entity, array $path, array $properties = [], $default = [], CacheableMetadata $cacheableMetadata = NULL): mixed {
    if ($cacheableMetadata) {
      $cacheableMetadata->addCacheableDependency($entity);
    }
    $value = $default;
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
      $value = $this->recurseEntity($field->entity, $path, $properties, $default, $cacheableMetadata);
    }
    elseif ($field instanceof FieldItemListInterface) {
      $value = $field->getValue();
      if ($property) {
        $value = $value[0][$property] ?? $default;
      }
    }
    if ($properties && is_array($value)) {
      $v = [];
      foreach ($value as $delta => $val) {
        foreach ($properties as $name => $prop) {
          $v[$delta][$name] = $val[$prop] ?? NULL;
        }
      }
      $value = $v;
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
  public function getMatchesAsOptions(ComponentShapePluginInterface $shape, string $entityTypeId = NULL, string $entityBundle = NULL, bool $all = FALSE): array {
    $options = [];
    $matches = $this->getMatches($shape, $entityTypeId, $entityBundle, $all);
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
   * @param bool $all
   *   (optional) Whether to match all fields. Defaults to FALSE.
   *
   * @return array
   *   An array of matches, sorted by weight and title.
   */
  public function getMatches(ComponentShapePluginInterface $shape, string $entityTypeId = NULL, string $entityBundle = NULL, bool $all = FALSE): array {
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
   * @return array
   *   An array of matched definitions.
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
  private function matchAll(EntityDataDefinitionInterface $entityDataDefinition, int $level, array $parentDefinitions = []): array {
    $matches = [];
    $fieldDefinitions = $this->dataDefinitions($entityDataDefinition);
    foreach ($fieldDefinitions as $fieldDefinition) {
      assert($fieldDefinition instanceof FieldDefinitionInterface);
      if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
        continue;
      }
      $parentFieldDefinitions = array_merge($parentDefinitions, [$fieldDefinition]);
      $matches[$this->key($parentFieldDefinitions)] = [
        'title' => $this->label($parentFieldDefinitions),
        'group' => $this->group($parentFieldDefinitions),
        'definition' => $fieldDefinition,
        'weight' => $level,
      ];

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
  private function entityDefinitions(EntityDataDefinitionInterface $entityDataDefinition, ComponentShapePluginInterface $shape): array {
    $isRequired = $shape->isRequired();
    $fieldDefinitions = [];

    $entityType = $this->entityTypeManager->getDefinition($entityDataDefinition->getEntityTypeId());
    if ($entityType->hasKey('label')) {
      $fieldName = '_entity:label';
      $label = $entityDataDefinition->getLabel();
      $label = ($label ? '(' . $label . ') ' : '') . $this->t('Label');
      $fieldDefinitions[$fieldName] = BaseFieldDefinition::create('string')
        ->setLabel($label)
        ->setName($fieldName)
        ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
        ->setRequired($isRequired);
    }
    foreach ($entityType->getLinkTemplates() as $templateId => $template) {
      if (substr($templateId, 0, 10) === 'alchemist.') {
        continue;
      }
      $fieldName = '_entity:link:' . $templateId;
      $label = $entityDataDefinition->getLabel();
      $label = '(' . $this->t('Link') . ') ' . ucwords(str_replace([
        '-',
        '_',
        '.',
      ], ' ', $templateId));
      $fieldDefinitions[$fieldName] = BaseFieldDefinition::create('link')
        ->setLabel($label)
        ->setName($fieldName)
        ->setTargetEntityTypeId($entityDataDefinition->getEntityTypeId())
        ->setRequired($isRequired);
    }
    return $fieldDefinitions;
  }

}
