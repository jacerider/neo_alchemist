<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentLibraryController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field) {
    /** @var \Drupal\neo_alchemist\ComponentStorage $storage */
    $storage = $this->entityTypeManager()->getStorage('neo_component');

    $rows = [];
    foreach ($storage->loadByEntity($neo_field->getEntity()) as $component) {
      $row = [];
      $row['name'] = $component->label();

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $neo_field->toUrl('add')->setRouteParameter('neo_component', $component->id()),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];

      $rows[] = $row;
    }

    $build = [
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
    ];

    return $build;
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Select the component to add to %label: %field_label', [
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
