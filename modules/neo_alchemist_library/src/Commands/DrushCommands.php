<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\neo_alchemist_library\BuildAdvisor;
use Drupal\neo_alchemist_library\Registry\ComponentInstaller;
use Drupal\neo_alchemist_library\Registry\RegistryClient;
use Drupal\neo_alchemist_library\Registry\RegistryException;
use Drush\Commands\DrushCommands as CoreCommands;

/**
 * Drush commands for the Neo Alchemist component registry.
 */
class DrushCommands extends CoreCommands {

  /**
   * Constructs the command class.
   */
  public function __construct(
    protected readonly RegistryClient $registryClient,
    protected readonly ComponentInstaller $installer,
    protected readonly BuildAdvisor $buildAdvisor,
  ) {
    parent::__construct();
  }

  /**
   * List components available from the configured registry.
   *
   * @command neo:alchemist:list
   * @usage drush neo:alchemist:list
   *   List installable starter components.
   * @aliases neoa-list
   * @field-labels
   *   name: Name
   *   title: Title
   *   status: Status
   *   description: Description
   * @default-fields name,title,status,description
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The available components.
   */
  public function listComponents($options = ['format' => 'table']): RowsOfFields {
    try {
      $index = $this->registryClient->getIndex();
    }
    catch (RegistryException $e) {
      throw new \Exception($e->getMessage());
    }

    $rows = [];
    foreach ($index as $name => $component) {
      $rows[$name] = [
        'name' => $name,
        'title' => (string) ($component['title'] ?? $name),
        'status' => (string) ($component['status'] ?? 'stable'),
        'description' => (string) ($component['description'] ?? ''),
      ];
    }

    if ($rows === []) {
      $this->io()->warning('The registry returned no components.');
    }

    return new RowsOfFields($rows);
  }

  /**
   * Add a starter component into a theme's components directory.
   *
   * @param string $component
   *   The registry component machine name to install.
   * @param array $options
   *   The command options.
   *
   * @command neo:alchemist:add
   * @option name
   *   New machine name for the installed component (defaults to $component).
   * @option theme
   *   Target theme machine name (defaults to the active front theme).
   * @option force
   *   Overwrite an existing component folder.
   * @option build
   *   Run the theme build (`npm run deploy`) after installing.
   * @usage drush neo:alchemist:add list_s1
   *   Install list_s1 into the active front theme.
   * @usage drush neo:alchemist:add list_s1 --name=featured_grid --force
   *   Install renamed to featured_grid, overwriting if present.
   * @usage drush neo:alchemist:add list_s1 --build
   *   Install, then compile assets with the theme build.
   * @aliases neoa-add
   */
  public function addComponent(
    string $component,
    array $options = [
      'name' => NULL,
      'theme' => NULL,
      'force' => FALSE,
      'build' => FALSE,
    ],
  ): int {
    // Verify the component exists before prompting.
    try {
      if (!$this->registryClient->has($component)) {
        $this->io()->error(sprintf('Component "%s" was not found in the registry. Run `drush neo:alchemist:list` to see what is available.', $component));
        return self::EXIT_FAILURE;
      }
    }
    catch (RegistryException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    // Determine the install name (defaults to the source name; press enter to
    // keep it). In non-interactive mode Drush returns the default.
    $target = $options['name'] ?: $this->io()->ask('Install as (machine name)', $component);

    try {
      $result = $this->installer->install(
        $component,
        $target,
        $options['theme'] ?: NULL,
        (bool) $options['force'],
      );
    }
    catch (RegistryException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    // Report.
    if ($result->installed) {
      $this->io()->success(sprintf(
        'Installed %s into theme "%s".',
        implode(', ', $result->installed),
        $result->theme,
      ));
    }
    if ($result->written) {
      $this->io()->listing($result->written);
    }
    if ($result->skipped) {
      $this->io()->note("Skipped:\n" . implode("\n", $result->skipped));
    }
    foreach ($result->warnings as $warning) {
      $this->io()->warning($warning);
    }

    if (!$result->hasChanges()) {
      // Nothing written usually means an unforced collision.
      return self::EXIT_FAILURE;
    }

    // Compile assets, or tell the developer how to.
    if ($options['build']) {
      $this->io()->section('Running theme build (npm run deploy)');
      $this->buildAdvisor->runBuild(fn(string $buffer) => $this->output()->write($buffer));
      $this->io()->success('Build finished — check the output above for errors.');
    }
    else {
      $this->io()->text($this->buildAdvisor->guidanceMessage());
    }

    return self::EXIT_SUCCESS;
  }

}
