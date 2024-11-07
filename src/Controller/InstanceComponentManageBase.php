<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EntityComponentTrait;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Returns responses for Neo | Alchemist routes.
 */
abstract class InstanceComponentManageBase extends ControllerBase {

  use EntityComponentControllerTrait;
  use EntityComponentTrait;
  use IconTranslationTrait;

  /**
   * Builds the response.
   */
  public function build(ComponentTreeItem $fieldItem) {
    $build = [];
    $instances = $fieldItem->getComponents();
    if ($instances) {
      $build['table'] = [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Name'),
          'status' => $this->t('Status'),
          'operations' => $this->t('Operations'),
        ],
        '#rows' => [],
      ];
      foreach ($instances as $instance) {
        $row = [];
        $row['name']['data']['#markup'] = $instance->label() . ' <small>(' . $instance->uuid() . ')</small>';
        if (!$instance->isComponentPublished()) {
          $row['status']['data']['#markup'] = $this->adminIcon('Disabled Globally')->iconOnly()->asTooltip();
        }
        else {
          if ($instance->isPublished()) {
            $row['status']['data']['#markup'] = $this->adminIcon('Enabled')->iconOnly()->asTooltip();
          }
          else {
            $row['status']['data']['#markup'] = $this->adminIcon('Disabled')->iconOnly()->asTooltip();
          }
        }

        $links = [];
        if ($instance->access('update')) {
          $links['edit'] = [
            'title' => $this->adminIcon('Edit'),
            'url' => $instance->toUrl('edit'),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        if ($instance->access('sort')) {
          $links['sort'] = [
            'title' => $this->adminIcon('Sort'),
            'url' => $instance->toUrl('sort'),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        if ($instance->access('delete')) {
          $links['remove'] = [
            'title' => $this->adminIcon('Remove'),
            'url' => $instance->toUrl('delete'),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        $row['operations']['data'] = [
          '#type' => 'operations',
          '#links' => $links,
        ];

        $build['table']['#rows'][] = $row;
      }
    }

    $build['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add component'),
      '#url' => $fieldItem->toUrl('library'),
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#attributes' => [
        'class' => ['use-ajax', 'btn'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 700,
        ]),
      ],
    ];

    if (!$fieldItem->belongsToFieldConfig()) {
      // Allow reset only for entity-based components.
      $build['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset component'),
        '#url' => $fieldItem->toUrl('reset'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-outline'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    if (count($instances) > 1) {
      $build['sort'] = [
        '#type' => 'link',
        '#title' => $this->t('Sort components'),
        '#url' => $fieldItem->toUrl('sort'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-outline'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }
    return $build;
  }

}
