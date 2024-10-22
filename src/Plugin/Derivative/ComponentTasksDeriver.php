<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives local tasks for entity types.
 */
class ComponentTasksDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs an entity local tasks deriver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (!$this->derivatives) {
      $neoFields = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');
      foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
        if ($entityType instanceof ContentEntityTypeInterface && $entityType->hasLinkTemplate('alchemist')) {
          $entityTypeId = $entityType->id();
          $base_route = NULL;
          if ($entityType->hasLinkTemplate('canonical')) {
            $base_route = "entity.$entityTypeId.canonical";
          }
          elseif ($entityType->hasLinkTemplate('edit-form')) {
            $base_route = "entity.$entityTypeId.edit-form";
          }
          if ($base_route) {
            $this->derivatives[$entityTypeId] = [
              'route_name' => "entity.$entityTypeId.alchemist",
              'title' => 'Layout',
              'base_route' => $base_route,
              'weight' => 15,
            ] + $base_plugin_definition;
            if (isset($neoFields[$entityTypeId])) {
              foreach ($neoFields[$entityTypeId] as $fieldName => $field) {
                $this->derivatives["$entityTypeId.$fieldName"] = [
                  'route_name' => "entity.$entityTypeId.alchemist.$fieldName",
                  'title' => 'Layout',
                  'base_route' => $base_route,
                  'alchemist_field_name' => $fieldName,
                  'parent_id' => "entity.neo_component_tasks:$entityTypeId",
                  'class' => 'Drupal\neo_alchemist\Plugin\Menu\LocalTask\EntityComponentLocalTask',
                ] + $base_plugin_definition;
              }
            }
            // foreach (array_filter($entityType->getFields(), function ($field) {
            //   return $field->getType() === 'neo_component_tree';
            // }) as $field) {
            //   $field_name = $field->getName();
            //   $this->derivatives["$entityTypeId.$field_name"] = [
            //     'route_name' => "entity.$entityTypeId.alchemist.layout",
            //     'title' => $field->getLabel(),
            //     'base_route' => "entity.$entityTypeId.alchemist",
            //     'weight' => 15,
            //   ] + $base_plugin_definition;
            // }
          }
        }
      }
    }
    return $this->derivatives;
  }

}
