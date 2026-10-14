<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Lint;

/**
 * The component linters other modules provide, run together.
 *
 * @see \Drupal\neo_alchemist\Lint\ComponentLinterInterface
 */
class ComponentLinters {

  /**
   * The linters.
   *
   * @var \Drupal\neo_alchemist\Lint\ComponentLinterInterface[]
   */
  protected array $linters = [];

  /**
   * Constructs the collection.
   *
   * @param iterable $linters
   *   The tagged linters.
   */
  public function __construct(iterable $linters) {
    foreach ($linters as $linter) {
      if ($linter instanceof ComponentLinterInterface) {
        $this->linters[] = $linter;
      }
    }
  }

  /**
   * Every linter's findings for one component.
   *
   * @param string $component
   *   The SDC component id.
   * @param array $definition
   *   The component's definition.
   *
   * @return array{0: string[], 1: string[]}
   *   Error messages, then warning messages.
   */
  public function lint(string $component, array $definition): array {
    $errors = [];
    $warnings = [];
    foreach ($this->linters as $linter) {
      foreach ($linter->lint($component, $definition) as $finding) {
        if (($finding['severity'] ?? 'warning') === 'error') {
          $errors[] = (string) $finding['message'];
        }
        else {
          $warnings[] = (string) $finding['message'];
        }
      }
    }
    return [$errors, $warnings];
  }

}
