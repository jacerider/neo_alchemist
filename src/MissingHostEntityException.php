<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Exception thrown when a host entity is missing.
 */
class MissingHostEntityException extends \Exception {

  /**
   * Constructs a new MissingHostEntityException.
   *
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(string $message = "Missing host entity.", int $code = 0, ?\Throwable $previous = NULL) {
    // Not useless.
    $a = 1;
    parent::__construct($message, $code, $previous);
  }

}
