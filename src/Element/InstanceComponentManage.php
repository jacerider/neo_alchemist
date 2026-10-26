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
    // One bar: Back · Layers · + Add lead it; the footer this used to need is
    // gone.
    $element['#top_start'] = ComponentManageHelper::buildNavigationOperations($neoField)
      + ComponentManageHelper::buildLayersToggle()
      + ComponentManageHelper::buildOperations($neoField, $element['#neo_modal'] ?? []);
    $element['#top_end'] = ComponentManageHelper::buildIframeOperations($neoField);
    // The save group trails the view controls, and is the part a write
    // re-renders (see ComponentManageHelper::buildDynamicOperations()).
    $element['#top_save'] = ComponentManageHelper::buildDynamicOperations($neoField);
    // The inspector docks on the right and opens with the selection
    // (components-parent.ts). Its header carries the selection's ops, from the
    // op inventory like the outline's.
    $chrome = _neo_alchemist_overlay_ops(\Drupal::service('neo_alchemist.editor_op_inventory')->ops());
    $element['#dock'] = TRUE;
    $element['#dock_ops'] = $chrome['inspector_ops'];
    $element['#dock_menu'] = $chrome['inspector_menu'];
    $element['#dock_close'] = TRUE;

    // Side panels other modules add: tabs in the right dock beside the
    // inspector, opened from their badge or a header toggle. With none
    // accessible nothing here changes the editor.
    $sidePanels = ComponentManageHelper::buildSidePanels($neoField);
    $sidePanels['cacheability']->applyTo($element);
    if ($sidePanels['panels']) {
      $element['#top_start'] += $sidePanels['toggles'];
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
