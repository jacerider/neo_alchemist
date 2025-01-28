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
        $list = $entity->get($fieldName);
        if ($list->isEmpty()) {
          $list->appendItem([]);
        }
        $neoField = $list->first();
      }
    }
    else {
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
    if (!empty($neoField) && ($defaults['neo_draft'] ?? FALSE)) {
      $neoField->enforceAsDraft();
    }
    return $neoField;
  }

}
