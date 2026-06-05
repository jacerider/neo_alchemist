<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Registry;

/**
 * Collects the outcome of an install so Drush and the UI can both report it.
 */
final class InstallResult {

  /**
   * Relative paths that were written.
   *
   * @var string[]
   */
  public array $written = [];

  /**
   * Relative paths that were skipped (already present, no --force).
   *
   * @var string[]
   */
  public array $skipped = [];

  /**
   * Human readable warnings.
   *
   * @var string[]
   */
  public array $warnings = [];

  /**
   * Component machine names that were installed (top level + dependencies).
   *
   * @var string[]
   */
  public array $installed = [];

  /**
   * The resolved target machine name of the requested component.
   */
  public string $targetName = '';

  /**
   * The theme the component(s) were installed into.
   */
  public string $theme = '';

  /**
   * Records a written file.
   */
  public function addWritten(string $relativePath): void {
    $this->written[] = $relativePath;
  }

  /**
   * Records a skipped file.
   */
  public function addSkipped(string $relativePath): void {
    $this->skipped[] = $relativePath;
  }

  /**
   * Records a warning.
   */
  public function addWarning(string $message): void {
    $this->warnings[] = $message;
  }

  /**
   * Records an installed component name.
   */
  public function addInstalled(string $name): void {
    if (!in_array($name, $this->installed, TRUE)) {
      $this->installed[] = $name;
    }
  }

  /**
   * TRUE when at least one file was written.
   */
  public function hasChanges(): bool {
    return $this->written !== [];
  }

}
