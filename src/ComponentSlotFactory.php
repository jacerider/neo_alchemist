<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Provides a factory for image objects.
 */
class ComponentSlotFactory {

  /**
   * The slot manager.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotPluginManager
   */
  protected $slotManager;

  /**
   * Constructs a new ComponentSlotFactory object.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotPluginManager $slot_manager
   *   The slot manager.
   */
  public function __construct(ComponentSlotPluginManager $slot_manager) {
    $this->slotManager = $slot_manager;
  }

  /**
   * Constructs a new Slot object.
   */
  public function get(ComponentInterface $component, string $name, array $schema, array $settings): ComponentSlot {
    return new ComponentSlot($this->slotManager, $component, $name, $schema, $settings);
  }

}
