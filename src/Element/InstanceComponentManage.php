<?php

namespace Drupal\neo_alchemist\Element;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_admin_icon\IconTranslationTrait;

/**
 * Provides a render element for instance component manager.
 */
#[RenderElement('neo_alchemist_manage')]
class InstanceComponentManage extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#theme' => 'neo_alchemist_manage',
      '#neoField' => NULL,
      '#pre_render' => [
        [$class, 'preRenderManage'],
      ],
    ];
  }

  /**
   * Prevents optional modals from rendering if they have no children.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   modal.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderManage($element) {
    $neoField = $element['#neo_field'];
    assert($neoField instanceof ComponentTreeItem);
    $element['#attached']['library'][] = 'neo_alchemist/instance.component.manage';
    $element['#attached']['drupalSettings']['neoAlchemist']['baseUrl'] = $neoField->toUrl()->toString();
    $element['#src'] = $neoField->toUrl('preview')->toString();
    $element['#top_start'] = [];
    $element['#top_end'] = [];

    $element['#top_start']['back'] = [
      '#type' => 'link',
      '#title' => neo_admin_icon(t('Back')),
      '#url' => $neoField->toUrl('collection'),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];

    if ($neoField->access('publish')) {
      $element['#top_start']['publish'] = [
        '#type' => 'link',
        '#title' => neo_admin_icon(t('Publish')),
        '#url' => $neoField->toUrl('publish'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-primary'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }
    if ($neoField->access('revert')) {
      $element['#top_start']['revert'] = [
        '#type' => 'link',
        '#title' => neo_admin_icon(t('Revert')),
        '#url' => $neoField->toUrl('revert'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-warning'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    if ($neoField->access('reset')) {
      // Allow reset only for entity-based components.
      $element['#top_start']['reset'] = [
        '#type' => 'link',
        '#title' => neo_admin_icon(t('Reset')),
        '#url' => $neoField->toUrl('reset'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-alert'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    // Resize.
    foreach ([
      'sm' => neo_admin_icon(t('Mobile'), 'mobile'),
      'md' => neo_admin_icon(t('Tablet'), 'tablet'),
      'lg' => neo_admin_icon(t('Desktop'), 'desktop'),
    ] as $key => $label) {
      $element['#top_end'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $neoField->toUrl('collection'),
        '#attributes' => [
          'id' => 'neo-alchemist--resize-' . $key,
          'class' => ['neo-alchemist--resize', 'btn', 'btn-xs', 'btn-outline'],
        ],
      ];
    }

    if ($neoField->access('create')) {
      $element['#bottom_start']['add'] = [
        '#type' => 'link',
        '#title' => neo_admin_icon(t('Add')),
        '#url' => $neoField->toUrl('library'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    if ($neoField->access('sort')) {
      $element['#bottom_start']['sort'] = [
        '#type' => 'link',
        '#title' => neo_admin_icon(t('Sort')),
        '#url' => $neoField->toUrl('sort'),
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

    return $element;
  }

}
