<?php

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\Component as ComponentPlugin;
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
    // These two act on whatever container the current selection resolves to,
    // which is not something a fixed label can express. The editor rewrites
    // the label text as the selection changes, so it always names its target —
    // hence the class hook on the label span.
    if ($instance->access('create')) {
      $build['add'] = [
        '#type' => 'link',
        '#title' => neo_icon_admin(t('Add'))->addLabelClass('neo-alchemist--action-label'),
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
        '#title' => neo_icon_admin(t('Sort'))->addLabelClass('neo-alchemist--action-label'),
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
   * Builds the toggle for the layers panel.
   *
   * Lives at the right of the bottom bar, opposite the Add/Sort actions, and
   * stays put whether the panel is open or not — it is the one fixed way to
   * reach the tree. Whether the panel starts open is decided per layout by
   * layersHasRegions() in components-parent.ts.
   *
   * @return array
   *   The toggle.
   */
  public static function buildLayersToggle() {
    return [
      'layers' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => neo_icon_admin(t('Layers'), 'sitemap')->render(),
        '#attributes' => [
          'type' => 'button',
          'class' => ['neo-alchemist--layers-toggle', 'btn', 'btn-outline', 'btn-xs', 'is-active:btn-primary'],
          'title' => t('Toggle the component tree'),
          'aria-expanded' => 'false',
        ],
      ],
    ];
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
      'width' => '640px',
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
    if ($instance instanceof ComponentTreeItem && $instance->access('purge')) {
      // The count decides whether the button appears at all — a locked field
      // with nothing stored has nothing to offer. access('purge') covers mode
      // and permission, deliberately without querying rows.
      $field = $instance->getFieldDefinition();
      $count = \Drupal::service('neo_alchemist.inert_component_data')->countFor(
        $field->getTargetEntityTypeId(),
        $field->getTargetBundle(),
        $field->getName(),
      );
      if ($count) {
        $build['purge'] = [
          '#type' => 'neo_modal_link',
          '#title' => neo_icon_admin(t('Purge stored data (@count)', ['@count' => $count])),
          '#url' => $instance->toUrl('purge'),
          '#attributes' => [
            'class' => ['use-ajax', 'btn', 'btn-xs', 'btn-alert'],
          ],
          '#modal' => $modalSettings,
        ];
      }
    }
    return $build;
  }

}
