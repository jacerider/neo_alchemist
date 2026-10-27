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
    // What the inspector's band counts a top-level component on ("2 of 4 on
    // Careers"); a field's default layout has no page of its own.
    if (!$neoField->belongsToFieldConfig() && ($page = $neoField->getEntity()?->label())) {
      $element['#attached']['drupalSettings']['neoAlchemist']['pageLabel'] = (string) $page;
    }
    $element['#id'] = ComponentManageHelper::getId($neoField);
    $element['#iframe_url'] = $neoField->toUrl('preview');
    $element['#iframe_start'] = [
      '#theme' => 'neo_alchemist_overlay',
    ];
    // The band (decision 28): Back, the title and the draft's status line
    // lead; + Add, Layers and a pill per side panel sit in the centre; the
    // panels' badges, Undo · Redo and the save group trail.
    $element['#top_start'] = ComponentManageHelper::buildNavigationOperations($neoField);
    $element['#top_center'] = ComponentManageHelper::buildOperations($neoField, $element['#neo_modal'] ?? [])
      + ComponentManageHelper::buildLayersToggle();
    $element['#top_end'] = [];
    // Device, zoom and Compare float on the canvas, apart from the band.
    $element['#preview'] = ComponentManageHelper::buildIframeOperations($neoField);
    // Only an entity's layout has a shared draft: a status line to say where
    // it stands, and Undo · Redo to step through it. Their wrappers stay put
    // while a write re-renders inside them.
    if (!$neoField->belongsToFieldConfig()) {
      $element['#top_status'] = ComponentManageHelper::buildDraftStatus($neoField);
      if ($neoField->access('update')) {
        $element['#top_end']['history'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['neo-alchemist-manage--top-history', 'flex', 'items-center', 'gap-1']],
        ] + ComponentManageHelper::buildHistoryOperations($neoField);
      }
    }
    // The save group trails everything, and is the part a write re-renders
    // (see ComponentManageHelper::buildDynamicOperations()).
    $element['#top_save'] = ComponentManageHelper::buildDynamicOperations($neoField);
    // The inspector docks on the right and opens with the selection
    // (components-parent.ts). Its header carries the selection's ops, from the
    // op inventory like the outline's.
    $chrome = _neo_alchemist_overlay_ops(\Drupal::service('neo_alchemist.editor_op_inventory')->ops());
    $element['#dock'] = TRUE;
    $element['#dock_ops'] = $chrome['inspector_ops'];
    $element['#dock_verbs'] = $chrome['inspector_verbs'];
    $element['#dock_close'] = TRUE;

    // Side panels other modules add: shown in the left dock in place of
    // Layers, each opened by its own pill beside Layers. With none accessible
    // nothing here changes the editor.
    $sidePanels = ComponentManageHelper::buildSidePanels($neoField);
    $sidePanels['cacheability']->applyTo($element);
    if ($sidePanels['panels']) {
      $element['#top_center'] += $sidePanels['toggles'];
      $element['#side_panels'] = $sidePanels['panels'];
      $element['#attached']['drupalSettings']['neoAlchemist']['panels'] = $sidePanels['settings'];
      $element['#attached']['library'][] = 'neo_alchemist/panels';
    }
    // Panel badges lead the band's trailing group. The band is never
    // re-rendered, so their client halves keep them current. One container,
    // one gap: the badges sit beside what follows rather than in a wrapper of
    // their own.
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
