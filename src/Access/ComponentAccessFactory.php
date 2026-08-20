<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\neo_alchemist\ComponentInterface;

/**
 * Provides a factory for component access objects.
 */
class ComponentAccessFactory {

  /**
   * The access manager.
   *
   * @var \Drupal\neo_alchemist\Access\ComponentAccessPluginManager
   */
  protected $accessManager;

  /**
   * Constructs a new ComponentAccessFactory object.
   *
   * @param \Drupal\neo_alchemist\Access\ComponentAccessPluginManager $access_manager
   *   The access manager.
   */
  public function __construct(ComponentAccessPluginManager $access_manager) {
    $this->accessManager = $access_manager;
  }

  /**
   * Constructs a new Access object.
   */
  public function get(ComponentInterface $component, array $settings = []): ComponentAccess {
    return new ComponentAccess($this->accessManager, $component, $settings);
  }

}
