<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\Html;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Helper for build manage panes.
 */
class ComponentManageHelper {

  /**
   * The element ID.
   *
   * @var string
   */
  protected static $id = 'neo-alchemist-manage';

  /**
   * Gets the element ID.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface|null $instance
   *   The field item.
   *
   * @return string
   *   The element ID.
   */
  public static function getId(ComponentTreeItem|ComponentInterface $instance = NULL): string {
    $id = static::$id;
    if ($instance instanceof ComponentInterface) {
      return $id . '-' . Html::getId($instance->isNew() ? $instance->id() : $instance->uuid());
    }
    if ($instance instanceof ComponentTreeItem) {
      $entity = $instance->getEntity();
      return $id . '-' . Html::getId($entity->isNew() ? $instance->getFieldDefinition()->getName() : $entity->id());
    }
    return $id;
  }

  /**
   * Builds the operations that change size of iframe.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The operations.
   */
  public static function buildIframeOperations(ComponentTreeItem|ComponentInterface $instance) {
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['flex gap-4'],
      ],
    ];

    $build['scale'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['btn-group'],
      ],
    ];
    // Scale.
    foreach ([
      'full' => neo_admin_icon(t('100%'), 'expand'),
      '75' => neo_admin_icon(t('75%'), 'compress'),
      '50' => neo_admin_icon(t('50%'), 'compress-arrows-alt'),
    ] as $key => $label) {
      $build['scale'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $instance->toUrl('collection'),
        '#attributes' => [
          'class' => ['neo-alchemist--scale', 'btn', 'btn-xs', 'btn-outline'],
          'data-size' => $key,
        ],
      ];
    }

    $build['size'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['btn-group'],
      ],
    ];
    // Resize.
    foreach ([
      'desktop' => neo_admin_icon(t('Desktop'), 'desktop'),
      'tablet' => neo_admin_icon(t('Tablet'), 'tablet'),
      'mobile' => neo_admin_icon(t('Mobile'), 'mobile'),
    ] as $key => $label) {
      $build['size'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $instance->toUrl('collection'),
        '#attributes' => [
          'class' => ['neo-alchemist--focus', 'btn', 'btn-xs', 'btn-outline'],
          'data-size' => $key,
        ],
      ];
    }
    return $build;
  }

  /**
   * Builds the operations that change state.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The operations.
   */
  public static function buildOperations(ComponentTreeItem|ComponentInterface $instance) {
    $build = [];
    $modalSettings = [
      'width' => '100%',
      'height' => '100%',
      'neo' => [
        'displaceTop' => '0px',
        'displaceBottom' => '0px',
      ],
    ];
    if ($instance->access('create')) {
      $build['add'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_admin_icon(t('Add')),
        '#url' => $instance->toUrl('library'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-primary', 'btn-xs'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('sort')) {
      $build['sort'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_admin_icon(t('Sort')),
        '#url' => $instance->toUrl('sort'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-outline', 'btn-xs'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    return $build;
  }

  /**
   * Builds the operations that change state.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The operations.
   */
  public static function buildDynamicOperations(ComponentTreeItem|ComponentInterface $instance) {
    $build = [];
    $modalSettings = [
      'neo' => [
        'displaceTop' => '0px',
        'displaceBottom' => '0px',
      ],
    ];
    $build['back'] = [
      '#type' => 'link',
      '#title' => neo_admin_icon(t('Back'), 'arrow-circle-left'),
      '#url' => $instance->toUrl('collection'),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];
    if ($instance->access('publish')) {
      $build['publish'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_admin_icon(t('Publish')),
        '#url' => $instance->toUrl('publish'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-primary'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('revert')) {
      $build['revert'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_admin_icon(t('Revert')),
        '#url' => $instance->toUrl('revert'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-warning'],
          'data-dialog-type' => 'modal',
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('reset')) {
      // Allow reset only for entity-based components.
      $build['reset'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_admin_icon(t('Reset')),
        '#url' => $instance->toUrl('reset'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-alert'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    return $build;
  }

}
