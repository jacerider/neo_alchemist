<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\RenderableInterface;

/**
 * Interface for neo_component_slot plugins.
 */
interface ComponentSlotPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface, RenderableInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the plugin instance UUID.
   */
  public function uuid(): ?string;

  /**
   * Returns the summarized configuration of the slot plugin.
   *
   * @return array
   *   An array of summarized configuration of the slot plugin.
   */
  public function settingsSummary(): array;

}
