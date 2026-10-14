<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Lint;

/**
 * A lint rule for a component's definition, from another module.
 *
 * `drush neo:alchemist:validate` runs every service tagged
 * `neo_alchemist.component_linter` beside its own rules, so a module that
 * reads a key of its own from `.component.yml` can check it where component
 * authors (and agents) already look.
 */
interface ComponentLinterInterface {

  /**
   * The problems in one component's definition.
   *
   * @param string $component
   *   The SDC component id, e.g. "front:locations".
   * @param array $definition
   *   The component's definition, as the SDC plugin manager holds it.
   *
   * @return array<int, array{severity: 'error'|'warning', message: string}>
   *   The findings; an error fails the validation, a warning does not.
   */
  public function lint(string $component, array $definition): array;

}
