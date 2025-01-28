<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Provides a factory for image objects.
 */
class ComponentSlotFactory {

  /**
   * Constructs a new Slot object.
   */
  public function get(ComponentInterface $component, string $name, array $schema): ComponentSlot {
    return new ComponentSlot($component, $name, $schema);
  }

}
