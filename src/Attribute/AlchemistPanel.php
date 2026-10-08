<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a side panel in the Alchemist layout editor.
 *
 * A panel is a floating box beside the Layers panel, owned by another module,
 * with its own key in the shared layout draft. Discovered in
 * src/Plugin/AlchemistPanel/ of any module.
 *
 * @see \Drupal\neo_alchemist\Panel\AlchemistPanelInterface
 * @see \Drupal\neo_alchemist\Panel\AlchemistPanelManager
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AlchemistPanel extends AttributeBase {

  /**
   * Constructs a new AlchemistPanel attribute.
   *
   * @param string $id
   *   The plugin ID. Also the panel's key in the shared draft and the {panel}
   *   segment of its route, so it must be lowercase letters, digits and
   *   underscores.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The panel's name, shown on its toggle and in its header.
   * @param string|null $icon
   *   (optional) A neo_icon name for the toggle.
   * @param int $weight
   *   (optional) Toggle order; lower sits further left.
   * @param string $width
   *   (optional) The floating panel's CSS width.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?string $icon = NULL,
    public readonly int $weight = 0,
    public readonly string $width = '24rem',
  ) {}

}
