<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Generators\Validator;

use Drupal\Core\Theme\ComponentPluginManager;

/**
 * Validates that service with a given name exists.
 */
final class NeoComponentExists {

  /**
   * Constructs the object.
   */
  public function __construct(
    private readonly string $themeId,
    private readonly ComponentPluginManager $pluginManagerSdc,
  ) {}

  /**
   * Validates that the component exists.
   *
   * @throws \UnexpectedValueException
   */
  public function __invoke(string $value): string {
    if (!$this->pluginManagerSdc->hasDefinition($this->themeId . ':' . $value)) {
      throw new \UnexpectedValueException('Component does not exists.');
    }
    return $value;
  }

}
