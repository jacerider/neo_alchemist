<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;

/**
 * Provides methods for matching fields between content entities and shapes.
 */
final class MatcherReference extends MatcherBase {

  /**
   * Get available reference fields for a shape.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string|null $entityBundle
   *   The entity bundle.
   *
   * @return array
   *   An array of field definitions.
   */
  public function getReferences(string $entityTypeId, ?string $entityBundle = NULL): array {
    $references = [];
    $dataType = implode(':', array_filter([
      'entity',
      $entityTypeId,
      $entityBundle,
    ]));
    $entityDataDefinition = EntityDataDefinition::createFromDataType($dataType);
    $references = $this->getReferenceDefinitions($entityDataDefinition, $this->maxLevels);
    return $references;
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
  public function getReferencesAsOptions($entityTypeId = NULL, ?string $entityBundle = NULL): array {
    $options = [];
    $matches = $this->getReferences($entityTypeId, $entityBundle);
    foreach ($matches as $key => [
      'title' => $title,
      'group' => $group,
    ]) {
      $options[ucwords($group)][$key] = $title;
    }
    return $options;
  }

  /**
   * Get reference definitions for a given entity data definition.
   *
   * This method retrieves reference definitions for a given entity data
   * definition at a specified level of recursion. It inspects the field
   * definitions of the entity data definition and recursively checks for
   * reference definitions.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $dd
   *   The entity data definition to inspect.
   * @param int $level
   *   The level of recursion to check for reference definitions.
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface[] $parentDefinitions
   *   (optional) An array of parent definitions for context. Defaults to an
   *   empty array.
   *
   * @return array
   *   An array of reference definitions.
   */
  private function getReferenceDefinitions(EntityDataDefinitionInterface $dd, int $level, array $parentDefinitions = []) {
    $references = [];
    $fieldDefinitions = $this->dataDefinitions($dd);

    foreach ($fieldDefinitions as $fieldDefinition) {
      assert($fieldDefinition instanceof FieldDefinitionInterface);
      if ($fieldDefinition instanceof ComponentFieldConfigInterface) {
        continue;
      }
      $properties = $this->dataDefinitions($fieldDefinition);
      foreach ($properties as $propertyName => $property) {
        if ($this->isReference($property)) {
          $parentFieldDefinitions = array_merge($parentDefinitions, [$fieldDefinition]);
          $references[$this->key($parentFieldDefinitions, $propertyName)] = [
            'title' => $this->label($parentFieldDefinitions),
            'group' => $this->group($parentFieldDefinitions),
            'definition' => $fieldDefinition,
          ];
          if ($level === 0) {
            continue;
          }
          if ($property instanceof DataReferenceDefinitionInterface && is_a($property->getClass(), EntityReference::class, TRUE)) {
            $target = $property->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            $references += $this->getReferenceDefinitions($target, $level - 1, $parentFieldDefinitions);
          }
        }
      }
    }
    return $references;
  }

  /**
   * Get a reference entity for a given key.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param string $key
   *   The key.
   * @param bool $force
   *   Whether to force the return of a new entity if an existing entity is not
   *   found.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   The cacheable metadata to attach to the reference entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The reference entity, if found.
   */
  public function getReferenceEntity(ContentEntityInterface $entity, string $key, bool $force = FALSE, ?CacheableMetadata $cacheableMetadata = NULL): ?EntityInterface {
    if ($entity->isNew() || $force) {
      $references = $this->getReferences($entity->getEntityTypeId());
      if (isset($references[$key])) {
        $reference = $references[$key];
        $entityTypeId = $reference['definition']->getSetting('target_type');

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $data = [];
        if ($bundleKey = $entityType->getKey('bundle')) {
          $bundles = $reference['definition']->getSetting('handler_settings')['target_bundles'] ?: [$entityTypeId];
          $bundle = reset($bundles);
          $data[$bundleKey] = $bundle;
        }
        return $this->entityTypeManager->getStorage($entityTypeId)->create($data);
      }
    }
    else {
      $path = explode('.', $key);
      $existingEntity = $this->recursiveReferenceEntity($entity, $path, $cacheableMetadata);
      if (!$existingEntity && $force) {
        return $this->getReferenceEntity($entity, $key, TRUE);
      }
      return $existingEntity;
    }
    return NULL;
  }

  /**
   * Recursively retrieves a reference entity for a given path.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param array $path
   *   The path to the reference entity.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   The cacheable metadata to attach to the reference entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The reference entity, if found.
   */
  private function recursiveReferenceEntity(ContentEntityInterface $entity, array $path, ?CacheableMetadata $cacheableMetadata = NULL): ?EntityInterface {
    if ($cacheableMetadata) {
      $cacheableMetadata->addCacheableDependency($entity);
    }
    $value = NULL;
    $key = array_shift($path);
    [$fieldName] = explode(':', $key . ':');
    if (!$entity->hasField($fieldName)) {
      return $value;
    }
    $field = $entity->get($fieldName);
    if ($field->isEmpty()) {
      return $value;
    }
    // If we have a reference field and there is a path, recurse.
    if ($field instanceof EntityReferenceFieldItemListInterface) {
      if (!$field->entity) {
        return $value;
      }
      if (!empty($path)) {
        $value = $this->recursiveReferenceEntity($field->entity, $path, $cacheableMetadata);
      }
      else {
        $value = $field->entity;
      }
    }
    return $value;
  }

}
