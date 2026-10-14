<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Lint;

use Drupal\neo_alchemist\Lint\ComponentLinterInterface;

/**
 * A linter that reads a key of its own from the definition.
 *
 * Registered only by the tests that use it.
 */
class ProbeLinter implements ComponentLinterInterface {

  /**
   * {@inheritdoc}
   */
  public function lint(string $component, array $definition): array {
    return isset($definition['probe']) ? [] : [
      ['severity' => 'error', 'message' => "$component declares no probe."],
      ['severity' => 'warning', 'message' => 'A warning.'],
    ];
  }

}
