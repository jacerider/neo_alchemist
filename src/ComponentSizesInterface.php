<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Interface for dealing with sizes.
 */
interface ComponentSizesInterface {

  /**
   * Set the sizes allowed for this shape.
   *
   * @param string[] $sizes
   *   The sizes.
   *
   * @return $this
   *   The called object.
   */
  public function setSizes(array $sizes): self;

  /**
   * Get the sizes allowed for this shape.
   *
   * @return string[]
   *   The sizes.
   */
  public function getSizes(): array;

  /**
   * Check if a size is allowed for this shape.
   *
   * @param string|null $size
   *   The size to check.
   *
   * @return bool
   *   TRUE if the size is allowed, FALSE otherwise.
   */
  public function allowSize(?string $size = NULL): bool;

}
