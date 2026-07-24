<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
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
    private readonly EntityFieldManagerInterface $entityFieldManager,
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
            // Entity routes.
            $this->derivatives[$entityTypeId] = [
              'route_name' => "entity.$entityTypeId.alchemist",
              'title' => 'Layout',
              'base_route' => $base_route,
              'weight' => 0.5,
            ] + $base_plugin_definition;
            if (isset($neoFields[$entityTypeId])) {
              foreach ($neoFields[$entityTypeId] as $fieldName => $field) {
                $fieldNameKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
                $this->derivatives["$entityTypeId.$fieldName"] = [
                  'route_name' => "entity.$entityTypeId.alchemist.manage",
                  'route_parameters' => [
                    'neo_field' => $fieldNameKey,
                  ],
                  'title' => 'Field Layout',
                  'base_route' => $base_route,
                  'parent_id' => "entity.neo_component_tasks:$entityTypeId",
                  'class' => 'Drupal\neo_alchemist\Plugin\Menu\LocalTask\EntityComponentLocalTask',
                ] + $base_plugin_definition;
              }
            }
            // Field routes.
            if ($routeName = $entityType->get('field_ui_base_route')) {
              $this->derivatives["field_ui.$entityTypeId"] = [
                'route_name' => "entity.$entityTypeId.field_ui.alchemist",
                'title' => 'Layout',
                'base_route' => $routeName,
                'weight' => 0.1,
              ] + $base_plugin_definition;
              if (isset($neoFields[$entityTypeId])) {
                foreach ($neoFields[$entityTypeId] as $fieldName => $field) {
                  foreach ($neoFields[$entityTypeId] as $fieldName => $field) {
                    $fieldNameKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
                    $this->derivatives["field_ui.$entityTypeId.$fieldName"] = [
                      'route_name' => "entity.$entityTypeId.field_ui.alchemist.manage",
                      'route_parameters' => [
                        'neo_field' => $fieldNameKey,
                      ],
                      'title' => 'Field Layout',
                      'base_route' => $base_route,
                      'parent_id' => "entity.neo_component_tasks:field_ui.$entityTypeId",
                      'class' => 'Drupal\neo_alchemist\Plugin\Menu\LocalTask\FieldComponentLocalTask',
                    ] + $base_plugin_definition;
                  }
                }
              }
            }
          }
        }
      }
    }
    return $this->derivatives;
  }

}
