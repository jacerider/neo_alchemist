<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\Process\Process;

/**
 * Advises on (and optionally runs) the front-end build after an install.
 *
 * A freshly installed component's Tailwind classes / JS only appear once the
 * theme's npm/Vite/Tailwind build runs. When the `npm start` watcher is active
 * (Neo dev mode) that happens automatically; otherwise the developer must run
 * `npm run deploy`. This service reports which case applies and can run the
 * build on demand. Integration with neo_build is optional.
 */
final class BuildAdvisor {

  /**
   * Constructs the BuildAdvisor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler (used to detect the optional neo_build integration).
   * @param string $appRoot
   *   The Drupal docroot; the npm build runs from its parent (project root).
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly string $appRoot,
  ) {}

  /**
   * Returns TRUE when the Neo dev watcher (npm start) is active.
   *
   * Guarded so the module works in projects without neo_build.
   */
  public function isWatching(): bool {
    if (!$this->moduleHandler->moduleExists('neo_build') || !method_exists('\Drupal\neo_build\NeoBuild', 'getNeoState')) {
      return FALSE;
    }
    return (bool) \Drupal\neo_build\NeoBuild::getNeoState('dev', FALSE);
  }

  /**
   * Returns TRUE when neo_build is present to run/advise the build.
   */
  public function neoBuildAvailable(): bool {
    return $this->moduleHandler->moduleExists('neo_build');
  }

  /**
   * The shell command a developer runs to compile assets.
   */
  public function buildCommand(): string {
    return 'ddev exec npm run deploy';
  }

  /**
   * Returns watcher-aware guidance about compiling the new component.
   */
  public function guidanceMessage(): string {
    if (!$this->neoBuildAvailable()) {
      return 'Run your theme build to compile the new component\'s styles and scripts.';
    }
    if ($this->isWatching()) {
      return 'The Vite watcher is running — the component\'s styles and scripts will compile automatically. Restart `npm start` only if a brand-new file is not picked up.';
    }
    return sprintf('Compile the component\'s styles and scripts with `%s` (or start the watcher: `ddev ssh` then `npm start`).', $this->buildCommand());
  }

  /**
   * Runs the production build (`npm run deploy`) from the project root.
   *
   * Best-effort: streams the real build output and ignores the process exit
   * code, because the neo build CLI exits non-zero even on success. `bash -ic`
   * mirrors the project's `ddev npm` wrapper so nvm/node load.
   *
   * @param callable $onOutput
   *   Receives output chunks (string) as the build runs.
   *
   * @return int
   *   The process exit code (unreliable; for information only).
   */
  public function runBuild(callable $onOutput): int {
    $projectRoot = dirname($this->appRoot);
    $process = new Process(['bash', '-ic', 'npm run deploy'], $projectRoot, NULL, NULL, NULL);
    $process->run(function (string $type, string $buffer) use ($onOutput): void {
      $onOutput($buffer);
    });
    return $process->getExitCode() ?? 0;
  }

}
