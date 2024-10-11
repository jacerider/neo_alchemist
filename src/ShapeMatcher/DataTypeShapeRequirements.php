<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ShapeMatcher;

/**
 * Describes a set of shape requirements for a Drupal data type.
 *
 * @see \Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement
 */
final class DataTypeShapeRequirements {

  /**
   * @param \Drupal\neo_alchemist\ShapeMatcher\DataTypeShapeRequirement[] $requirements
   */
  public function __construct(
    public readonly array $requirements,
  ) {
    foreach ($this->requirements as $requirement) {
      if (!$requirement instanceof DataTypeShapeRequirement) {
        throw new \LogicException();
      }
    }
  }

}
