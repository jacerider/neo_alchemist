<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Filter;

use Drupal\neo_alchemist\ComponentInterface;

/**
 * Provides a factory for component filter objects.
 */
class ComponentFilterFactory {

  /**
   * The filter manager.
   *
   * @var \Drupal\neo_alchemist\Filter\ComponentFilterPluginManager
   */
  protected $filterManager;

  /**
   * Constructs a new ComponentFilterFactory object.
   *
   * @param \Drupal\neo_alchemist\Filter\ComponentFilterPluginManager $filter_manager
   *   The filter manager.
   */
  public function __construct(ComponentFilterPluginManager $filter_manager) {
    $this->filterManager = $filter_manager;
  }

  /**
   * Constructs a new Filter object.
   */
  public function get(ComponentInterface $component, array $settings = []): ComponentFilter {
    return new ComponentFilter($this->filterManager, $component, $settings);
  }

}
