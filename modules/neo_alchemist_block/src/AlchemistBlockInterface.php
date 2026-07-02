<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for the Alchemist block config entity.
 */
interface AlchemistBlockInterface extends ConfigEntityInterface {

  /**
   * Gets the description.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string;

  /**
   * Gets the component tree/props values.
   *
   * @return array
   *   An array with 'tree' and 'props' keys, or an empty array.
   */
  public function getComponentValues(): array;

  /**
   * Sets the component tree/props values.
   *
   * @param array $values
   *   An array with 'tree' and 'props' keys, or an empty array.
   *
   * @return $this
   */
  public function setComponentValues(array $values): self;

  /**
   * Checks if the block has component values.
   *
   * @return bool
   *   TRUE if the block has component values, FALSE otherwise.
   */
  public function hasComponentValues(): bool;

}
