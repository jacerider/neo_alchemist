<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentSlotInterface {

  /**
   * Gets the component.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component instance.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Gets the slot name.
   *
   * @return string
   *   The slot name.
   */
  public function getName(): string;

  /**
   * Gets the slot title.
   *
   * @return array
   *   The slot title.
   */
  public function getTitle(): string;

  /**
   * Gets the slot description.
   *
   * @return string
   *   The slot description.
   */
  public function getDescription(): string;

}
