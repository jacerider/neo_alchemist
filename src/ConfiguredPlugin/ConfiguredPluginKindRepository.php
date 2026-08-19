<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

/**
 * Resolves a configured-plugin kind by its machine name.
 *
 * The shared controller reads the kind from a route default and the shared
 * form reads it from the entity form operation; both arrive here as a string.
 */
final class ConfiguredPluginKindRepository {

  /**
   * The kinds, keyed by machine name.
   *
   * @var \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface[]
   */
  private array $kinds = [];

  /**
   * Constructs the repository.
   *
   * @param \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface ...$kinds
   *   The kinds this module ships.
   */
  public function __construct(ConfiguredPluginKindInterface ...$kinds) {
    foreach ($kinds as $kind) {
      $this->kinds[$kind->id()] = $kind;
    }
  }

  /**
   * Gets one kind.
   *
   * @param string $id
   *   The machine name.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface
   *   The kind.
   *
   * @throws \InvalidArgumentException
   *   When no kind answers to that name. A route or an entity form handler
   *   naming an unknown kind is a wiring mistake, not a runtime condition.
   */
  public function get(string $id): ConfiguredPluginKindInterface {
    if (!isset($this->kinds[$id])) {
      throw new \InvalidArgumentException(sprintf('There is no "%s" configured plugin kind. Known kinds: %s.', $id, implode(', ', array_keys($this->kinds))));
    }
    return $this->kinds[$id];
  }

}
