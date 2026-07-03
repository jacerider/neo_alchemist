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
  public static function getId(ComponentTreeItem|ComponentInterface|null $instance = NULL): string {
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
        'class' => ['btn-group', 'flush'],
      ],
    ];
    // Scale.
    foreach ([
      'full' => neo_icon_admin(t('100%'), 'expand'),
      '75' => neo_icon_admin(t('75%'), 'compress'),
      '50' => neo_icon_admin(t('50%'), 'compress-arrows-alt'),
    ] as $key => $label) {
      $build['scale'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $instance->toUrl('collection'),
        '#attributes' => [
          'class' => ['neo-alchemist--scale', 'btn', 'btn-xs', 'btn-outline', 'is-active:btn-primary'],
          'data-size' => $key,
        ],
      ];
    }

    $build['size'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['btn-group', 'flush'],
      ],
    ];
    // Resize.
    foreach ([
      'desktop' => neo_icon_admin(t('Desktop'), 'desktop'),
      'tablet' => neo_icon_admin(t('Tablet'), 'tablet'),
      'mobile' => neo_icon_admin(t('Mobile'), 'mobile'),
    ] as $key => $label) {
      $build['size'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $instance->toUrl('collection'),
        '#attributes' => [
          'class' => ['neo-alchemist--focus', 'btn', 'btn-xs', 'btn-outline', 'is-active:btn-primary'],
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
   * @param array $modal_settings
   *   The modal settings.
   *
   * @return array
   *   The operations.
   */
  public static function buildOperations(ComponentTreeItem|ComponentInterface $instance, array $modal_settings = []) {
    $build = [];
    $modalSettings = $modal_settings + [
      'width' => '100%',
      'height' => '100%',
      'displaceTop' => '0px',
      'displaceBottom' => '0px',
    ];
    if ($instance->access('create')) {
      $build['add'] = [
        '#type' => 'link',
        '#title' => neo_icon_admin(t('Add')),
        '#url' => $instance->toUrl('library'),
        '#attributes' => [
          'class' => ['neo-alchemist--action', 'btn', 'btn-primary', 'btn-xs'],
          'data-action' => 'library',
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('sort')) {
      $build['sort'] = [
        '#type' => 'link',
        '#title' => neo_icon_admin(t('Sort')),
        '#url' => $instance->toUrl('sort'),
        '#attributes' => [
          'class' => ['neo-alchemist--action', 'btn', 'btn-outline', 'btn-xs'],
          'data-action' => 'sort',
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
      '#title' => neo_icon_admin(t('Back'), 'arrow-circle-left'),
      '#url' => $instance->toUrl('collection'),
      '#attributes' => [
        'class' => ['btn', 'btn-xs', 'btn-outline'],
      ],
    ];
    if ($instance->access('reset')) {
      // Allow reset only for entity-based components.
      $build['reset'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Reset')),
        '#url' => $instance->toUrl('reset'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-alert'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('revert')) {
      $build['revert'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Revert')),
        '#url' => $instance->toUrl('revert'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-warning'],
          'data-dialog-type' => 'modal',
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('publish')) {
      $build['publish'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Save')),
        '#url' => $instance->toUrl('publish'),
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-success'],
        ],
        '#modal' => $modalSettings,
      ];
    }
    return $build;
  }

}
