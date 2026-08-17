<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Exception thrown when a host entity is missing.
 *
 * Thrown by ComponentInterface::getTargetEntity() when the component declares a
 * target entity type it cannot produce a placeholder for. Callers that validate
 * component trees outside a host entity are expected to catch this.
 *
 * @see \Drupal\neo_alchemist\ComponentInterface::getTargetEntity()
 * @see \Drupal\neo_alchemist\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
 */
class MissingHostEntityException extends \Exception {

}
