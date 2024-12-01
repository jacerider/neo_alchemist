<?php

namespace Drupal\neo_alchemist\Routing;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for a custom ID.
 */
class FieldComponentParamConverter implements ParamConverterInterface {

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
    return isset($definition['type']) && $definition['type'] == 'neo_alchemist_field_component';
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $neoField = $defaults['neo_field'] ?? NULL;
    if ($neoField instanceof ComponentTreeItem) {
      // The $value can be either a component id or a component UUID.
      if ($neoComponent = $this->entityTypeManager->getStorage('neo_component')->load($value)) {
        /** @var \Drupal\neo_alchemist\ComponentInstanceInterface $neoComponent */
        return $neoField->createComponent($neoComponent);
      }
      return $neoField->getComponent($value);
    }
    return NULL;
  }

}
