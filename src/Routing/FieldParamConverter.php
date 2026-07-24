<?php

namespace Drupal\neo_alchemist\Routing;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for a custom ID.
 */
class FieldParamConverter implements ParamConverterInterface {

  /**
   * Entity type manager which performs the upcasting in the end.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new EntityConverter.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return isset($definition['type']) && $definition['type'] == 'neo_alchemist_field';
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|null
   *   The field item.
   */
  public function convert($value, $definition, $name, array $defaults) {
    $neoField = NULL;
    $fieldName = ComponentFieldConfig::getFieldnameFromKey($defaults['neo_field']);
    if (isset($defaults['entity_type_id']) && isset($defaults[$defaults['entity_type_id']]) && $defaults[$defaults['entity_type_id']] instanceof ContentEntityInterface) {
      $entity = $defaults[$defaults['entity_type_id']];
      if ($entity->hasField($fieldName)) {
        if ($entity->getEntityType()->isRevisionable()) {
          /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
          $storage = $this->entityTypeManager->getStorage($defaults['entity_type_id']);
          $currentRevisionId = $entity->getRevisionId();
          $latestRevisionId = $storage->getLatestRevisionId($entity->id());
          if ($currentRevisionId !== $latestRevisionId) {
            $entity = $storage->loadRevision($latestRevisionId);
          }
        }

        // Work on a detached copy of the field item list. Route-driven draft
        // state must never live on the statically-cached entity: param
        // conversion re-runs re-entrantly (access checks via checkNamedRoute,
        // path validation, sub-requests), and each run would mutate the shared
        // item — clobbering a publish flow's enforceAsDraft(FALSE) mid-save
        // and bleeding draft values into canonical renders. Each conversion
        // gets its own copy instead; committing syncs the value back onto the
        // entity in ComponentTreeItem::saveComponents().
        $list = clone $entity->get($fieldName);
        if ($list->isEmpty()) {
          $list->appendItem([]);
        }
        $neoField = $list->first();
      }
    }
    elseif (isset($defaults['entity_type_id'])) {
      $entityTypeId = $defaults['entity_type_id'];
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      $bundle = $defaults['bundle'] ?? $entityTypeId;
      if ($bundleEntityType = $entityType->getBundleEntityType()) {
        if (isset($defaults[$bundleEntityType])) {
          $bundle = $defaults[$bundleEntityType]->id();
        }
      }
      $fields = array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
      if (isset($fields[$fieldName]) && $fields[$fieldName] instanceof ComponentFieldConfigInterface) {
        /** @var \Drupal\neo_alchemist\ComponentFieldConfigInterface $field */
        $field = $fields[$fieldName];
        $neoField = $field->getFieldItem();
      }
    }
    else {
      throw new \InvalidArgumentException('Unable to convert parameter without entity_type_id.');
    }
    if (!empty($neoField) && ($defaults['neo_draft'] ?? FALSE)) {
      $neoField->enforceAsDraft();
    }
    return $neoField;
  }

}
