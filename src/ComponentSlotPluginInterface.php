<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Render\RenderableInterface;

/**
 * Interface for neo_component_slot plugins.
 *
 * A slot plugin is a configured plugin that also renders. Its manager has
 * always narrowed the add picker with ::isApplicable(), but the contract was
 * only ever written on the base class — the shared interface now declares it.
 */
interface ComponentSlotPluginInterface extends ConfiguredPluginInterface, RenderableInterface, CacheableResponseInterface {

  /**
   * Returns the plugin instance UUID.
   */
  public function uuid(): ?string;

}
