<?php

namespace Drupal\neo_alchemist\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Provides a render element for instance component manager.
 */
#[RenderElement('neo_alchemist_manage')]
class InstanceComponentManage extends RenderElementBase {

  /**
   * The element ID.
   *
   * @var string
   */
  public static $id = 'neo-alchemist-manage';

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#theme' => 'neo_alchemist_manage',
      '#neo_field' => NULL,
      '#neo_modal' => [],
      '#side_panels' => [],
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
    $element['#attached']['library'][] = 'neo_alchemist/components.parent';
    $element['#attached']['drupalSettings']['neoAlchemist']['baseUrl'] = $neoField->toUrl()->toString();
    $element['#attached']['drupalSettings']['neoAlchemist']['hybrid'] = $neoField->isHybridScope();
    $element['#id'] = ComponentManageHelper::getId($neoField);
    $element['#iframe_url'] = $neoField->toUrl('preview');
    $element['#iframe_start'] = [
      '#theme' => 'neo_alchemist_overlay',
    ];
    $element['#top_start'] = [];
    $element['#top_end'] = [];

    $element['#top_start'] = ComponentManageHelper::buildDynamicOperations($neoField);
    $element['#top_end'] = ComponentManageHelper::buildIframeOperations($neoField);
    $element['#bottom_start'] = ComponentManageHelper::buildOperations($neoField, $element['#neo_modal'] ?? []);
    $element['#bottom_end'] = ComponentManageHelper::buildLayersToggle();

    // Side panels other modules add: their toggles sit left of Layers. With
    // none accessible nothing here changes the editor.
    $sidePanels = ComponentManageHelper::buildSidePanels($neoField);
    $sidePanels['cacheability']->applyTo($element);
    if ($sidePanels['panels']) {
      $element['#bottom_end'] = $sidePanels['toggles'] + $element['#bottom_end'];
      $element['#side_panels'] = $sidePanels['panels'];
      $element['#attached']['drupalSettings']['neoAlchemist']['panels'] = $sidePanels['settings'];
      $element['#attached']['library'][] = 'neo_alchemist/panels';
    }
    // Panel badges lead the top bar's end, left of the scale controls. The
    // top bar is never re-rendered, so their client halves keep them current.
    // One container, one gap: the badges sit beside the scale and device
    // groups rather than in a wrapper of their own.
    if ($sidePanels['badges']) {
      $badges = [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-alchemist--badges', 'contents']],
      ] + $sidePanels['badges'];
      $element['#top_end'] = ['badges' => $badges] + $element['#top_end'];
    }

    return $element;
  }

}
