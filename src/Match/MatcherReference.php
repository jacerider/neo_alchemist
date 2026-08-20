<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Match;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;

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
   * @param string|null $entityTypeId
   *   (optional) The entity type ID to match against. Defaults to NULL.
   * @param string|null $entityBundle
   *   (optional) The entity bundle to match against. Defaults to NULL.
   * @param string|null $targetEntityTypeId
   *   (optional) The target entity type ID to filter references.
   *   Defaults to NULL.
   * @param string|null $targetEntityBundle
   *   (optional) The target entity bundle to filter references.
   *   Defaults to NULL.
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
  public function getReferencesAsOptions($entityTypeId = NULL, ?string $entityBundle = NULL, ?string $targetEntityTypeId = NULL, ?string $targetEntityBundle = NULL): array {
    $options = [];
    $matches = $this->getReferences($entityTypeId, $entityBundle);
    foreach ($matches as $key => [
      'title' => $title,
      'group' => $group,
      'definition' => $definition,
    ]) {
      if ($targetEntityTypeId) {
        if ($definition->getSetting('target_type') !== $targetEntityTypeId) {
          continue;
        }
        if ($targetEntityBundle) {
          $bundles = $definition->getSetting('handler_settings')['target_bundles'] ?: [$targetEntityTypeId];
          if (!in_array($targetEntityBundle, $bundles, TRUE)) {
            continue;
          }
        }
      }
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
   * Get a single reference entity for a given entity type and key.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $key
   *   The key.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The reference entity, if found.
   */
  public function getReferenceEntityByEntityType(string $entityTypeId, string $key): ?EntityInterface {
    return $this->buildPlaceholderEntity($entityTypeId, $key);
  }

  /**
   * Get a single reference entity for a given key.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param string $key
   *   The key.
   * @param bool $placeholder
   *   Ask for a prototypical entity of the target type instead of the real
   *   reference. Form-building callers pass TRUE because they only need the
   *   target's entity type and bundle, which no stored value can supply while
   *   the host entity is still unsaved.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   The cacheable metadata to attach to the reference entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The reference entity, if found.
   */
  public function getReferenceEntity(ContentEntityInterface $entity, string $key, bool $placeholder = FALSE, ?CacheableMetadata $cacheableMetadata = NULL): ?EntityInterface {
    if ($entity->isNew() || $placeholder) {
      return $this->buildPlaceholderEntity($entity->getEntityTypeId(), $key);
    }
    // An empty, missing or dangling reference yields no field, and the target
    // may itself be gone; either way there is no entity to return.
    return $this->getReferenceField($entity, $key, $cacheableMetadata)?->entity;
  }

  /**
   * Get a reference field for a given key.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   * @param string $key
   *   The key.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   The cacheable metadata to attach to the reference entity.
   *
   * @return \Drupal\Core\Field\EntityReferenceFieldItemListInterface|null
   *   The reference field, if found.
   */
  public function getReferenceField(ContentEntityInterface $entity, string $key, ?CacheableMetadata $cacheableMetadata = NULL):  ?EntityReferenceFieldItemListInterface {
    if (!$entity->isNew()) {
      return $this->recurseReferenceField($entity, explode('.', $key), $cacheableMetadata);
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
   * @return \Drupal\Core\Field\EntityReferenceFieldItemListInterface|null
   *   The reference entity, if found.
   */
  private function recurseReferenceField(ContentEntityInterface $entity, array $path, ?CacheableMetadata $cacheableMetadata = NULL): ?EntityReferenceFieldItemListInterface {
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
        $value = $this->recurseReferenceField($field->entity, $path, $cacheableMetadata);
      }
      else {
        $value = $field;
      }
    }
    return $value;
  }

  /**
   * Builds an unsaved entity of a reference's target type and bundle.
   *
   * Callers use this at form-build time to learn what a reference points at
   * when no stored value can tell them — the host entity is new, or they are
   * describing the target rather than reading it. The entity is never saved.
   *
   * @param string $entityTypeId
   *   The entity type ID that declares the reference.
   * @param string $key
   *   The reference key.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   An unsaved entity of the target type, or NULL if the key is unknown.
   */
  private function buildPlaceholderEntity(string $entityTypeId, string $key): ?EntityInterface {
    $references = $this->getReferences($entityTypeId);
    if (isset($references[$key])) {
      $reference = $references[$key];
      $entityTypeId = $reference['definition']->getSetting('target_type');

      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      $data = [];
      if ($bundleKey = $entityType->getKey('bundle')) {
        $bundles = ($reference['definition']->getSetting('handler_settings')['target_bundles'] ?? NULL) ?: [$entityTypeId];
        $bundle = reset($bundles);
        $data[$bundleKey] = $bundle;
      }
      return $this->entityTypeManager->getStorage($entityTypeId)->create($data);
    }
    return NULL;
  }

}
