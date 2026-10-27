<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_tooltip\Tooltip;

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
   * The wording for the side panel's toolbar.
   *
   * Taken from the instance's own state rather than from which route built it.
   * The add and edit routes do currently mean exactly "new" and "saved", but
   * that is a coincidence of routing, and a third caller would have to know it.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $instance
   *   The component being placed or edited.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The toolbar title.
   */
  public static function getToolbarTitle(ComponentInterface $instance): TranslatableMarkup {
    return $instance->isNew()
      ? new TranslatableMarkup('Add component')
      : new TranslatableMarkup('Edit component');
  }

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
   * Returns the cache-busted URI of an SDC's own thumbnail.png, if it has one.
   *
   * Core reads component thumbnails straight off disk with a fixed filename,
   * so a re-captured thumbnail lands at the URL its predecessor already
   * occupies in the browser cache. The modified time is the only thing that
   * distinguishes them, hence the token.
   *
   * @param \Drupal\Core\Plugin\Component|null $component
   *   The SDC plugin instance.
   *
   * @return string|null
   *   A root-relative URI, or NULL when the component ships no thumbnail.
   *
   * @see \Drupal\Core\Theme\Component\ComponentMetadata::getThumbnailPath()
   */
  public static function sdcThumbnailUri(?ComponentPlugin $component): ?string {
    $path = $component?->metadata->getThumbnailPath();
    if (!$path) {
      return NULL;
    }
    // getThumbnailPath() is relative to the Drupal root, which is how it can
    // be handed to #theme image directly, but filemtime() needs the real one.
    $modified = @filemtime(\Drupal::root() . '/' . $path);
    return $modified ? $path . '?v=' . $modified : $path;
  }

  /**
   * Returns the URI of the shared placeholder thumbnail.
   *
   * @return string
   *   A root-relative URI.
   */
  public static function placeholderThumbnailUri(): string {
    return \Drupal::service('extension.list.module')->getPath('neo_alchemist') . '/images/thumbnail.jpg';
  }

  /**
   * Returns the thumbnail URI for a raw SDC or a saved component.
   *
   * @param \Drupal\Core\Plugin\Component|\Drupal\neo_alchemist\ComponentInterface|null $source
   *   The SDC plugin instance, or the component entity wrapping one.
   *
   * @return string
   *   A URI suitable for #theme image, never empty.
   */
  public static function thumbnailUri(ComponentPlugin|ComponentInterface|null $source): string {
    if ($source instanceof ComponentInterface) {
      // The entity resolves its own three-tier fallback (captured config file,
      // then the SDC's own thumbnail, then the placeholder).
      return $source->getThumbnail() ?: static::placeholderThumbnailUri();
    }
    return static::sdcThumbnailUri($source) ?: static::placeholderThumbnailUri();
  }

  /**
   * Builds the thumbnail cell shared by the component listings.
   *
   * @param \Drupal\Core\Plugin\Component|\Drupal\neo_alchemist\ComponentInterface|null $source
   *   The SDC plugin instance, or the component entity wrapping one.
   * @param string $alt
   *   The image alt text.
   * @param array $attributes
   *   Image attributes to merge over the defaults.
   *
   * @return array
   *   A table cell definition.
   */
  public static function buildThumbnailCell(ComponentPlugin|ComponentInterface|null $source, string $alt = '', array $attributes = []): array {
    return [
      'style' => 'width: 100px;',
      'data' => [
        '#theme' => 'image',
        '#uri' => static::thumbnailUri($source),
        '#alt' => $alt,
        '#attributes' => $attributes + [
          'style' => 'display: block; max-width: 80px; max-height: 80px',
        ],
        '#prefix' => '<div class="flex items-center justify-center">',
        '#suffix' => '</div>',
      ],
    ];
  }

  /**
   * Builds the preview dock: how the canvas shows the page.
   *
   * A dark pill floating at the bottom of the canvas, apart from the band:
   * these controls change how the page is looked at, not the page. Device
   * (Desktop, Tablet, Mobile — the shown one named, the others icons with
   * tooltips), then zoom (− and + step through 50%, 75%, 100% and Fit; the
   * value opens a menu of all four), then Compare (every size side by side).
   *
   * The zoom presets and the devices are toggle buttons, not links: they
   * change the view and go nowhere, and `aria-pressed` (kept in step with
   * `.is-active` by component-parent.ts) is what tells a screen reader which
   * one is on. component-parent.ts drives all of it and remembers the choices.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The dock's render array.
   */
  public static function buildIframeOperations(ComponentTreeItem|ComponentInterface $instance) {
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-alchemist--preview-dock'],
        'role' => 'toolbar',
        'aria-label' => t('Preview'),
      ],
    ];

    $build['size'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['flex', 'items-center', 'gap-0.5'],
        'role' => 'group',
        'aria-label' => t('Size'),
      ],
    ];
    foreach ([
      'desktop' => [t('Desktop'), t('Preview at desktop width'), t('Previewing at desktop width')],
      'tablet' => [t('Tablet'), t('Preview at tablet width'), t('Previewing at tablet width')],
      'mobile' => [t('Mobile'), t('Preview at mobile width'), t('Previewing at mobile width')],
    ] as $key => [$name, $tip, $shown]) {
      // The name is the label; the dock shows it only on the shown size
      // (component-parent.css). The tooltip says what the button does, which
      // names the size too, so the icon-only ones need nothing more; the shown
      // one's says so (component-parent.ts swaps it in as it opens).
      $build['size'][$key] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--focus', 'neo-alchemist--dock-device'],
          'data-size' => $key,
          'aria-pressed' => 'false',
          'data-alchemist-tip' => (string) $tip,
          'data-alchemist-tip-active' => (string) $shown,
          'data-neo-tooltip-detach' => 'true',
        ],
        'icon' => neo_icon_admin($name, $key)->addLabelClass('neo-alchemist--dock-device-label')->getRenderable(),
      ];
      (new Tooltip($tip, ['placement' => 'top']))->applyTo($build['size'][$key]);
    }

    $build['divider_zoom'] = static::buildDockDivider();
    $build['scale'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['neo-alchemist--zoom', 'relative', 'flex', 'items-center'],
        'role' => 'group',
        'aria-label' => t('Zoom'),
      ],
    ];
    $steps = [];
    foreach (['out' => [t('Zoom out'), 'minus', -1], 'in' => [t('Zoom in'), 'plus', 1]] as $key => [$name, $icon, $step]) {
      $steps[$key] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--zoom-step', 'neo-alchemist--dock-icon', 'aria-disabled:opacity-40'],
          'data-step' => (string) $step,
          'data-neo-tooltip-detach' => 'true',
        ],
        'icon' => neo_icon_admin($name, $icon)->iconOnly()->getRenderable(),
      ];
      (new Tooltip($name, ['placement' => 'top']))->applyTo($steps[$key]);
    }
    // − · the zoom in force, which opens the presets · +.
    $build['scale']['out'] = $steps['out'];
    $build['scale']['value'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => t('Fit'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['neo-alchemist--zoom-value'],
        'aria-haspopup' => 'true',
        'aria-expanded' => 'false',
        'aria-controls' => 'neo-alchemist-zoom-menu',
        'data-neo-tooltip-detach' => 'true',
      ],
    ];
    (new Tooltip(t('Choose a zoom level'), ['placement' => 'top']))->applyTo($build['scale']['value']);
    $build['scale']['in'] = $steps['in'];
    $build['scale']['menu'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'neo-alchemist-zoom-menu',
        'class' => ['neo-alchemist--zoom-menu'],
        'role' => 'group',
        'aria-label' => t('Zoom'),
        'hidden' => 'hidden',
      ],
    ];
    // Fit, the default, scales the frames to the canvas column
    // (component-parent.ts); the rest are fixed. The order − and + step in.
    foreach ([
      'fit' => t('Fit'),
      'full' => t('100%'),
      '75' => t('75%'),
      '50' => t('50%'),
    ] as $key => $label) {
      $build['scale']['menu'][$key] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $label,
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--scale'],
          'data-size' => $key,
          'aria-pressed' => 'false',
        ],
      ];
    }

    $build['divider_compare'] = static::buildDockDivider();
    // Compare: every size side by side, or one at a time.
    $build['compare'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'type' => 'button',
        'class' => ['neo-alchemist--compare', 'neo-alchemist--dock-device'],
        'aria-pressed' => 'false',
        'data-alchemist-tip' => (string) t('Show all sizes side by side'),
        'data-alchemist-tip-active' => (string) t('Showing all sizes side by side'),
        'data-neo-tooltip-detach' => 'true',
      ],
      'icon' => neo_icon_admin(t('Compare'), 'columns')->getRenderable(),
    ];
    (new Tooltip(t('Show all sizes side by side'), ['placement' => 'top']))->applyTo($build['compare']);
    return $build;
  }

  /**
   * Builds a rule between the preview dock's groups.
   *
   * @return array
   *   The rule's render array.
   */
  protected static function buildDockDivider(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'class' => ['neo-alchemist--dock-divider'],
        'aria-hidden' => 'true',
      ],
    ];
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
    // Add acts on whatever container the current selection resolves to (the
    // end of it), which is not something a fixed label can express, so the
    // editor says it in the button's tooltip as the selection changes. It leads
    // the band's centre, the light pill on the dark band: building the page is
    // what the editor is for. Save stays the one primary-coloured button. When
    // the band runs short of room it says just "Add" (headerFit() in
    // components-parent.ts).
    if ($instance->access('create')) {
      $build['add'] = [
        '#type' => 'link',
        '#title' => [
          // The full name is the icon's label; the short one only shows (and
          // only then reaches a screen reader) in place of it.
          'full' => neo_icon_admin(t('Add component'), 'plus')->addLabelClass('neo-alchemist--add-full')->getRenderable(),
          'short' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => t('Add'),
            '#attributes' => ['class' => ['neo-alchemist--add-short']],
          ],
        ],
        '#url' => $instance->toUrl('library'),
        '#attributes' => [
          'class' => ['neo-alchemist--action', 'neo-alchemist--add', 'neo-alchemist--band-add'],
          'data-action' => 'library',
        ],
        '#modal' => $modalSettings,
      ];
      static::applyBandTip($build['add'], t('Add a component to the end of the page'), t('Add component'));
    }
    return $build;
  }

  /**
   * Builds the toggle for the Layers dock.
   *
   * In the band's centre, after + Add, and stays put whether the dock is open
   * or not — it is the one fixed way to reach the tree. Whether the
   * dock starts open is decided per layout by layersHasRegions() in
   * components-parent.ts.
   *
   * @return array
   *   The toggle.
   */
  public static function buildLayersToggle() {
    $build = [
      'layers' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => neo_icon_admin(t('Layers'), 'sitemap')->render(),
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--layers-toggle', 'neo-alchemist--band-pill'],
          'aria-expanded' => 'false',
          'aria-controls' => 'neo-alchemist-layers',
        ],
      ],
    ];
    static::applyBandTip($build['layers'], t('See every component on the page and drag to reorder them'), t('Layers'));
    return $build;
  }

  /**
   * Gives a band-centre button its tooltip: what it does, not what it says.
   *
   * The label already names the button, so the tooltip only describes it, and
   * a button with no description has none. While the band is short of room and
   * the label is hidden, component-parent.ts puts the name back on top of the
   * tooltip as it opens. It reads both from the attributes set here, so Add can
   * rewrite its description as the selection changes.
   *
   * @param array $build
   *   The button's render array.
   * @param string|\Stringable $tip
   *   What the button does; empty for no tooltip while the label shows.
   * @param string|\Stringable $name
   *   The button's label.
   */
  protected static function applyBandTip(array &$build, string|\Stringable $tip, string|\Stringable $name): void {
    $tip = (string) $tip;
    $build['#attributes']['data-alchemist-tip'] = $tip;
    $build['#attributes']['data-alchemist-tip-name'] = (string) $name;
    // On the body: the band's stacking context would hold it under the crumbs.
    $build['#attributes']['data-neo-tooltip-detach'] = 'true';
    (new Tooltip($tip, ['placement' => 'bottom']))->applyTo($build);
  }

  /**
   * Builds the editor side panels the current user may use on a field item.
   *
   * Every piece the editor needs from the accessible AlchemistPanel plugins:
   * the panels the template puts in the left dock, each in place of Layers
   * rather than as a tab beside it; a band pill per panel, named for it, in
   * the band's centre beside Layers; each panel's badge, which goes to the
   * band's trailing group on its own (neo_seo: the SEO and Readability
   * scores); and the drupalSettings the client reads each panel's endpoint
   * from. With no panel registered, or none accessible, every list is empty
   * and the editor is unchanged.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   *
   * @return array
   *   Keyed 'toggles' and 'badges' (render arrays), 'panels' (the template's
   *   side_panels list of {id, label, width, body}), 'settings' (the
   *   drupalSettings.neoAlchemist.panels map, id => {label, url}) and
   *   'cacheability' (the access decisions' CacheableMetadata).
   */
  public static function buildSidePanels(ComponentTreeItem $item): array {
    $cacheability = new CacheableMetadata();
    $build = ['toggles' => [], 'panels' => [], 'settings' => [], 'badges' => [], 'cacheability' => $cacheability];
    /** @var \Drupal\neo_alchemist\Panel\AlchemistPanelManager $manager */
    $manager = \Drupal::service('plugin.manager.neo_alchemist_panel');
    foreach ($manager->accessiblePanels($item, \Drupal::currentUser(), $cacheability) as $id => $panel) {
      $definition = $panel->getPluginDefinition();
      $label = $panel->label();
      $build['panels'][] = [
        'id' => $id,
        'label' => $label,
        'width' => $definition['width'] ?? '24rem',
        'body' => $panel->build($item),
      ];
      $build['settings'][$id] = [
        'label' => $label,
        'url' => $item->toUrl('panel')->setRouteParameter('panel', $id)->toString(),
      ];
      // The panel's own button: its icon and name, and the panel's description
      // as its tooltip. The name gives way to the tooltip when the header runs
      // out of room (headerFit() in components-parent.ts).
      $toggle = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => neo_icon_admin($label, $definition['icon'] ?? NULL)->render(),
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--sidepanel-toggle', 'neo-alchemist--band-pill'],
          'data-alchemist-sidepanel' => $id,
          'aria-expanded' => 'false',
          'aria-controls' => 'neo-alchemist-layers',
        ],
      ];
      static::applyBandTip($toggle, $definition['description'] ?? '', $label);
      $build['toggles']['sidepanel_' . $id] = $toggle;
      // The badge's dropdown is added by panels.ts beside the badge content.
      // Apart from the panel's pill: a badge is read at a glance, the pill is
      // a place to go.
      $badge = $panel->badge($item);
      if ($badge) {
        $build['badges']['badge_' . $id] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['neo-alchemist--badge', 'relative', 'flex', 'items-center'],
            'data-alchemist-badge' => $id,
          ],
          'content' => $badge,
        ];
      }
    }
    return $build;
  }

  /**
   * Builds the header's leading group: the way out, and (later) navigation.
   *
   * Static for the life of the page, unlike the save group, which is
   * re-rendered after every write (see buildDynamicOperations()).
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The leading group's render array.
   */
  public static function buildNavigationOperations(ComponentTreeItem|ComponentInterface $instance): array {
    return [
      'back' => static::buildBackLink($instance->toUrl('collection')),
    ];
  }

  /**
   * Builds the band's Back: an icon, named in its tooltip.
   *
   * The screen's title sits right after it, so the word "Back" next to that
   * would only crowd it. Every screen's Back is this one, whatever it leads
   * to.
   *
   * @param \Drupal\Core\Url $url
   *   Where Back goes.
   *
   * @return array
   *   The link's render array.
   */
  public static function buildBackLink(Url $url): array {
    $build = [
      '#type' => 'link',
      '#title' => neo_icon_admin(t('Back'), 'arrow-left')->iconOnly(),
      '#url' => $url,
      '#attributes' => [
        'class' => ['neo-alchemist--band-icon'],
        'data-neo-tooltip-detach' => 'true',
      ],
    ];
    (new Tooltip(t('Back'), ['placement' => 'bottom']))->applyTo($build);
    return $build;
  }

  /**
   * Builds the header's Undo and Redo buttons.
   *
   * Icons with the step named in a tooltip ("Undo Delete Hero S5"). A button
   * with nothing to do, or refused because a colleague wrote the draft since
   * this editor's last step, stays focusable and says why in its tooltip
   * rather than going dead: aria-disabled, not disabled. Re-rendered with the
   * save group after every write (see ComponentAjaxHelperTrait), into
   * `.neo-alchemist-manage--top-history`.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   *
   * @return array
   *   The two buttons' render array.
   *
   * @see \Drupal\neo_alchemist\EditorState\EditorHistory
   */
  public static function buildHistoryOperations(ComponentTreeItem $item): array {
    $state = \Drupal::service('neo_alchemist.editor_history')->state($item);
    $refused = $state['refused'] !== NULL
      ? t('@editor edited this layout since your last change', ['@editor' => static::draftUserName($state['refused'])])
      : NULL;
    $build = [
      // Per user and per write: never cached.
      '#cache' => ['max-age' => 0],
    ];
    foreach ([
      'undo' => [
        t('Undo'),
        t('Undo @step', ['@step' => (string) $state['undo']]),
        t('Nothing to undo'),
        'Meta+Z Control+Z',
      ],
      'redo' => [
        t('Redo'),
        t('Redo @step', ['@step' => (string) $state['redo']]),
        t('Nothing to redo'),
        'Meta+Shift+Z Control+Shift+Z Control+Y',
      ],
    ] as $direction => [$name, $named, $empty, $keys]) {
      $available = $state[$direction] !== NULL;
      $build[$direction] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => neo_icon_admin($name, $direction)->iconOnly()->render(),
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--history', 'neo-alchemist--band-icon', 'aria-disabled:opacity-40'],
          'data-history' => $direction,
          'data-url' => $item->toUrl('history')->setRouteParameter('direction', $direction)->toString(),
          'aria-keyshortcuts' => $keys,
        ],
      ];
      if ($available) {
        $build[$direction]['#attributes']['data-step'] = (string) $state[$direction];
      }
      else {
        $build[$direction]['#attributes']['aria-disabled'] = 'true';
        // What a shortcut that finds the button unavailable says.
        $build[$direction]['#attributes']['data-reason'] = (string) ($refused ?? $empty);
      }
      // On the body, not in the group (see the size toggles).
      $build[$direction]['#attributes']['data-neo-tooltip-detach'] = 'true';
      (new Tooltip((string) ($available ? $named : ($refused ?? $empty)), ['placement' => 'bottom']))->applyTo($build[$direction]);
    }
    return $build;
  }

  /**
   * Builds the band's status line: where the shared draft stands.
   *
   * Under the screen's title, so it reads as part of what is open rather than
   * as one more control: "Unsaved changes" with a warning dot while a draft
   * exists, "All changes saved" otherwise, then the presence chip when a
   * colleague last wrote the draft, since who else is in it belongs with
   * where it stands. Only an entity's layout has a draft, so only it has the
   * line.
   *
   * Re-rendered with the save group after every write, into
   * `.neo-alchemist-manage--top-status` (see ComponentAjaxHelperTrait and
   * panels.ts).
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $instance
   *   The field item.
   *
   * @return array
   *   The status line's render array.
   */
  public static function buildDraftStatus(ComponentTreeItem $instance): array {
    $hasDraft = $instance->hasDraft();
    $build = [
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['neo-alchemist--status', 'flex', 'items-center', 'gap-1.5'],
          'role' => 'status',
        ],
        'dot' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'class' => [
              'neo-alchemist--status-dot', 'size-2', 'rounded-full',
              $hasDraft ? 'bg-warning-500' : 'bg-success-500',
            ],
            'aria-hidden' => 'true',
          ],
        ],
        'label' => [
          '#plain_text' => $hasDraft ? t('Unsaved changes') : t('All changes saved'),
        ],
      ],
      // Per user and per write: never cached.
      '#cache' => ['max-age' => 0],
    ];
    if ($presence = static::buildDraftPresence($instance)) {
      $build['presence'] = $presence;
    }
    return $build;
  }

  /**
   * Builds the band's save group: what commits or abandons the shared draft.
   *
   * Trails the band: Save (the one primary-coloured button — the action the
   * editor exists for), then a ⋯ menu holding what an editor reaches for
   * rarely and must not hit by accident: Discard unsaved changes (Revert),
   * Reset and Purge. Where the draft stands is said by the status line under
   * the title (buildDraftStatus()). With no draft only ⋯ can appear, and only
   * when it holds Reset or Purge.
   *
   * This is the part re-rendered after every write — by the AJAX helper's
   * HtmlCommand and by panels.ts — into `.neo-alchemist-manage--top-save`.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|Drupal\neo_alchemist\ComponentInterface $instance
   *   The field item.
   *
   * @return array
   *   The save group's render array.
   */
  public static function buildDynamicOperations(ComponentTreeItem|ComponentInterface $instance) {
    $build = [];
    $modalSettings = [
      'width' => '640px',
      'neo' => [
        'displaceTop' => '0px',
        'displaceBottom' => '0px',
      ],
    ];
    if ($instance->access('publish')) {
      $build['publish'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Save'), 'check'),
        '#url' => $instance->toUrl('publish'),
        '#attributes' => [
          // A band pill in the primary colour, as an active toggle and the
          // preview dock's shown size are: not the theme's .btn, whose border
          // and glow set it apart from everything else on the band.
          'class' => ['use-ajax', 'neo-alchemist--band-pill', 'is-primary', 'neo-alchemist--band-save'],
        ],
        '#modal' => $modalSettings,
      ];
    }

    // The ⋯ menu. Its open/close lives in components-parent.ts.
    $more = [];
    $itemClasses = ['use-ajax', 'flex', 'w-full', 'items-center', 'gap-2', 'px-3', 'py-2', 'rounded', 'text-sm'];
    $destructive = ['text-alert-700', 'hover:bg-alert-50', 'hover:text-alert-800'];
    if ($instance->access('revert')) {
      // Revert, said as what it does. Destructive too, but it only drops what
      // has not been saved yet, so it is not dressed like Reset.
      $more['revert'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Discard unsaved changes'), 'undo-alt'),
        '#url' => $instance->toUrl('revert'),
        '#attributes' => [
          'class' => array_merge($itemClasses, ['text-base-800', 'hover:bg-base-100']),
          'data-dialog-type' => 'modal',
        ],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance->access('reset')) {
      // Allow reset only for entity-based components.
      $more['reset'] = [
        '#type' => 'neo_modal_link',
        '#title' => neo_icon_admin(t('Reset')),
        '#url' => $instance->toUrl('reset'),
        '#attributes' => ['class' => array_merge($itemClasses, $destructive)],
        '#modal' => $modalSettings,
      ];
    }
    if ($instance instanceof ComponentTreeItem && $instance->access('purge')) {
      // The count decides whether the item appears at all — a locked field
      // with nothing stored has nothing to offer. access('purge') covers mode
      // and permission, deliberately without querying rows.
      $field = $instance->getFieldDefinition();
      $count = \Drupal::service('neo_alchemist.inert_component_data')->countFor(
        $field->getTargetEntityTypeId(),
        $field->getTargetBundle(),
        $field->getName(),
      );
      if ($count) {
        $more['purge'] = [
          '#type' => 'neo_modal_link',
          '#title' => neo_icon_admin(t('Purge stored data (@count)', ['@count' => $count])),
          '#url' => $instance->toUrl('purge'),
          '#attributes' => ['class' => array_merge($itemClasses, $destructive)],
          '#modal' => $modalSettings,
        ];
      }
    }
    if ($more) {
      $menuId = Html::getUniqueId('neo-alchemist-more');
      $build['more'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['neo-alchemist--more', 'relative']],
        'toggle' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#attributes' => [
            'type' => 'button',
            'class' => ['neo-alchemist--more-toggle', 'neo-alchemist--band-icon'],
            'aria-expanded' => 'false',
            'aria-controls' => $menuId,
            'data-neo-tooltip-detach' => 'true',
          ],
          // A renderable, not markup: the panel endpoint renders this group
          // in isolation, outside any render context an eager render needs.
          'icon' => neo_icon_admin(t('More actions'), 'ellipsis-h')->iconOnly()->getRenderable(),
        ],
        'menu' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'id' => $menuId,
            'class' => [
              'neo-alchemist--more-menu',
              'absolute', 'right-0', 'top-full', 'mt-2', 'w-64', 'p-1', 'z-50',
              'rounded-lg', 'shadow-lg', 'border', 'bg-base-0', 'text-base-0-content',
            ],
            'hidden' => 'hidden',
          ],
        ] + $more,
      ];
      (new Tooltip(t('More actions'), ['placement' => 'bottom']))->applyTo($build['more']['toggle']);
    }
    return $build;
  }

  /**
   * Builds the presence indicator for a shared layout draft.
   *
   * The draft is the collaborative artifact two editors build together; this
   * surfaces who else has been in it and how recently — "Jane edited this 2
   * minutes ago" — so an editor does not unknowingly build on top of a
   * colleague mid-edit. It is a thin read of the draft's own last-editor and
   * last-modified metadata (no heartbeat, no separate presence table), and it
   * appears only when a colleague, rather than the current editor, last wrote
   * the draft: presence is about who *else* is here.
   *
   * The sentence is worth reading once, not worth a strip of toolbar forever,
   * so it renders as a chip in the band's status line: the colleague's
   * initials in an avatar, and the age abbreviated to "now" / "6m" / "3h".
   * Together those answer "is someone else in here, and just now?" at a
   * glance; the full sentence stays one hover away in the tooltip, and is what
   * a screen reader announces. See buildDraftStatus().
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $instance
   *   The field item whose shared draft is being edited.
   *
   * @return array
   *   The presence render element, or an empty array when there is no draft or
   *   the current editor is the one who last wrote it.
   */
  public static function buildDraftPresence(ComponentTreeItem $instance): array {
    $editorUid = $instance->draftLastEditor();
    if ($editorUid === NULL || $editorUid === (int) \Drupal::currentUser()->id()) {
      return [];
    }
    $name = static::draftUserName($editorUid);
    $modified = $instance->draftLastModified();
    $ago = $modified
      ? \Drupal::service('date.formatter')->formatTimeDiffSince($modified, ['granularity' => 1])
      : NULL;
    $label = $ago
      ? t('@editor edited this @time ago', ['@editor' => $name, '@time' => $ago])
      : t('@editor edited this', ['@editor' => $name]);
    $build = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        // In the band's status line, after the draft's state, set off by a
        // rule: who else is in the draft belongs with where it stands.
        'class' => [
          'neo-alchemist--presence',
          'flex', 'items-center', 'gap-1.5', 'pl-2', 'border-l', 'border-base-700',
          'text-xs', 'cursor-help',
        ],
        // Focusable, so the detail is reachable without a mouse: the tooltip
        // opens on focus as well as hover.
        'tabindex' => '0',
      ],
      // The avatar carries the "who" at the status line's height, so the chip
      // adds no row of text to it.
      'avatar' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => static::draftUserInitials($name),
        '#attributes' => [
          'class' => [
            'flex', 'items-center', 'justify-center', 'size-5',
            'rounded-full', 'bg-base-200', 'text-base-700',
            'text-[10px]', 'font-bold', 'leading-none', 'uppercase',
          ],
          'aria-hidden' => 'true',
        ],
      ],
      // The relative time drifts by the second, so the indicator can never be
      // cached — it is recomputed on every render of the editor toolbar.
      '#cache' => ['max-age' => 0],
    ];
    if ($modified) {
      $build['age'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => static::draftAgeAbbreviated($modified),
        '#attributes' => [
          'class' => ['tabular-nums'],
          'aria-hidden' => 'true',
        ],
      ];
    }
    // The glyphs above are decoration; this is what the sentence sounds like.
    $build['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => ['class' => ['sr-only']],
    ];
    // Everything the chip had to drop to stay glyph-sized lives in the
    // tooltip, which is where an editor goes once the chip has caught their
    // eye.
    $tooltip = new Tooltip(static::buildDraftPresenceDetail($instance, $editorUid, $label, $modified), [
      'placement' => 'bottom-start',
      'delay' => '[200, 0]',
    ]);
    $tooltip->applyTo($build);
    return $build;
  }

  /**
   * Builds the detail behind the presence chip.
   *
   * The chip answers "someone else, recently"; this answers the questions that
   * follow — exactly when, what the draft is, and who else is in it — so an
   * editor can decide whether to keep building or go ask. Publishing is called
   * out because it releases the draft whole: a colleague's unfinished work
   * goes out with yours, which is the one consequence worth knowing before
   * pressing Save.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $instance
   *   The field item whose shared draft is being edited.
   * @param int $editorUid
   *   The uid of the colleague who last wrote the draft.
   * @param \Drupal\Component\Render\MarkupInterface|string $label
   *   The presence sentence the chip abbreviates.
   * @param int|null $modified
   *   The draft's last-modified timestamp, when it has one.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The rendered tooltip content.
   */
  protected static function buildDraftPresenceDetail(ComponentTreeItem $instance, int $editorUid, MarkupInterface|string $label, ?int $modified): MarkupInterface {
    $detail = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['flex', 'flex-col', 'gap-1', 'text-left'],
        // Tippy would otherwise let these lines run to 600px, which reads as a
        // banner rather than a note. Inline because a max-width utility is not
        // in the compiled admin CSS, and one tooltip is not worth making every
        // site rebuild its assets.
        'style' => 'max-width:20rem',
      ],
      'headline' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $label,
        '#attributes' => ['class' => ['font-bold']],
      ],
    ];
    // Who first, then when, then what it means: the headline already names one
    // colleague, so the rest of the room belongs directly under it. The last
    // editor is not repeated, and nobody needs to be told they are in the
    // draft they are looking at.
    $others = array_diff($instance->draftContributors(), [$editorUid, (int) \Drupal::currentUser()->id()]);
    if ($others) {
      $detail['others'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => t('Also in this draft: @editors', [
          '@editors' => implode(', ', array_map(static::draftUserName(...), $others)),
        ]),
        '#attributes' => ['class' => ['font-bold', 'opacity-75']],
      ];
    }
    if ($modified) {
      // The chip rounds to one unit; the tooltip is where "3d" becomes a date
      // someone can line up against their own week.
      $detail['when'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => \Drupal::service('date.formatter')->format($modified, 'short'),
        '#attributes' => ['class' => ['opacity-75']],
      ];
    }
    $detail['what'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t('This is a shared layout draft. Saving publishes their work along with yours.'),
      '#attributes' => ['class' => ['opacity-75']],
    ];
    return \Drupal::service('renderer')->renderInIsolation($detail);
  }

  /**
   * Abbreviates how long ago the draft was last written.
   *
   * The chip is glyph-sized, so the age is a token — "now", "6m", "3h", "2d",
   * "5w" — rather than a phrase. Only the coarsest unit is kept: presence
   * answers "just now or a while ago", and a second unit would buy precision
   * nobody acts on at the cost of the width the chip exists to save. The unit
   * letters stay untranslated on purpose; the translated sentence is what the
   * tooltip and the screen-reader label carry.
   *
   * @param int $modified
   *   The draft's last-modified timestamp.
   *
   * @return string
   *   The abbreviated age.
   */
  protected static function draftAgeAbbreviated(int $modified): string {
    $seconds = max(0, \Drupal::time()->getRequestTime() - $modified);
    foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
      if ($seconds >= $size) {
        return intdiv($seconds, $size) . $unit;
      }
    }
    return (string) t('now');
  }

  /**
   * Reduces a contributor's display name to avatar initials.
   *
   * One or two letters, from the first two words of the name — enough for a
   * colleague to recognise who is in the draft, with the whole name a hover
   * away. A name that yields no letters at all (a purely symbolic username)
   * falls back to a dot, so the avatar is never an empty circle.
   *
   * @param string $name
   *   The contributor's display name.
   *
   * @return string
   *   The initials.
   */
  protected static function draftUserInitials(string $name): string {
    $initials = '';
    foreach (array_slice(preg_split('/[\s._-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2) as $word) {
      $initials .= mb_substr($word, 0, 1);
    }
    return $initials === '' ? '·' : mb_strtoupper($initials);
  }

  /**
   * Names the draft's contributors, optionally excluding one user.
   *
   * The publish confirmation names whose work is included before releasing the
   * draft as a whole, so an editor does not release a colleague's unfinished
   * component unaware. The publisher is excluded — they know what they are
   * publishing — and the set is cleared with the record on publish, discard and
   * revert, so a fresh draft never names a contributor from a released cycle.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $instance
   *   The field item whose shared draft is being published.
   * @param int|null $excludeUid
   *   A user id to omit (typically the publisher), or NULL to name everyone.
   *
   * @return string[]
   *   The contributor display names in first-write order.
   */
  public static function draftContributorNames(ComponentTreeItem $instance, ?int $excludeUid = NULL): array {
    $names = [];
    foreach ($instance->draftContributors() as $uid) {
      if ($excludeUid !== NULL && $uid === $excludeUid) {
        continue;
      }
      $names[] = static::draftUserName($uid);
    }
    return $names;
  }

  /**
   * Resolves a draft contributor's display name, or a neutral label.
   *
   * The store records raw user ids; loading the user is a presentation concern,
   * kept here rather than in the store. A user id that no longer resolves — a
   * deleted account that once wrote the draft — degrades to a neutral label so
   * presence and attribution never render a broken name or a bare number.
   *
   * @param int $uid
   *   The contributor's user id.
   *
   * @return string
   *   The display name, or a neutral label when the account does not resolve.
   */
  protected static function draftUserName(int $uid): string {
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    return $user ? $user->getDisplayName() : (string) t('Another editor');
  }

}
