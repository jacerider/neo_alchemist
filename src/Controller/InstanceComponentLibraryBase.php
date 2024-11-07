<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Returns responses for Neo | Alchemist routes.
 */
abstract class InstanceComponentLibraryBase extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build(ComponentTreeItem $fieldItem) {

    // @todo Filter by entity type/bundle.
    /** @var \Drupal\neo_alchemist\ComponentInterface[] $components */
    $properties = [
      'status' => 1,
    ];
    $components = $this->entityTypeManager()->getStorage('neo_component')->loadByProperties($properties);

    $rows = [];
    foreach ($components as $component) {
      $row = [];
      $row['name'] = $component->label();

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $fieldItem->toUrl('add')->setRouteParameter('neo_component', $component->id()),
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

}
