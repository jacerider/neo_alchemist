<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentShapeStylePluginInterface {

  /**
   * Gets all style options this shape can expose.
   *
   * Unlike ::getFieldOptions(), this returns the complete, unfiltered set of
   * options the shape offers, independent of any per-component widget settings
   * or the site-wide neo_alchemist.style_settings include/exclude
   * configuration. It is the source of truth used by the component style
   * settings form to let administrators choose which options are available.
   *
   * Shapes whose options come from the prop def `styles` schema get a default
   * implementation in StyleShapeBase. Shapes that compute their options in code
   * (e.g. SchemeShape) override this — which is how a style can be defined
   * purely in code, without a `styles` list in a prop def, and still be
   * configurable here.
   *
   * @return array
   *   An associative array keyed by option key. Each value is an array with:
   *   - label: (string) The human-readable label.
   *   - value: (string) The value applied when the option is selected (e.g. the
   *     CSS classes), or an empty string when not applicable.
   */
  public function getStyleOptions(): array;

}
