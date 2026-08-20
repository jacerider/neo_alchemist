<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\neo_alchemist\ComponentInterface;

/**
 * One plugin, picked and configured, stored on a component under a uuid.
 *
 * ConfiguredPluginWrapperTrait already noted that ComponentAccess and
 * ComponentFilter "are the same object wearing two labels" and extracted the
 * memoisation rule they shared. This is the type that observation implies:
 * the shared add/edit form and controller are written against it, so the two
 * families stopped needing a form and two controllers each.
 *
 * A wrapper adds whatever its own family needs on top — a filter carries a
 * title, a default value and an editability flag; an access rule answers an
 * operation — and those stay on the sub-interfaces.
 *
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperTrait
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginKindInterface
 */
interface ConfiguredPluginWrapperInterface {

  /**
   * Gets the human label for this configured plugin.
   *
   * @return string
   *   The label.
   */
  public function label(): string;

  /**
   * Gets the UUID this wrapper is stored under.
   *
   * @return string|null
   *   The UUID, or NULL when it has never been saved.
   */
  public function uuid(): ?string;

  /**
   * Whether the wrapper has never been saved onto the component.
   *
   * @return bool
   *   TRUE when it carries no uuid.
   */
  public function isNew(): bool;

  /**
   * Gets the component this plugin is configured on.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Gets the configured plugin id.
   *
   * @return string
   *   The plugin id, or '' when none is chosen.
   */
  public function getPluginId(): string;

  /**
   * Sets the configured plugin id.
   *
   * Writing a different id discards the settings and the memoised instance:
   * the outgoing plugin's settings cannot configure a different plugin.
   *
   * @param string $pluginId
   *   The plugin id.
   *
   * @return self
   *   The wrapper, for chaining.
   */
  public function setPluginId(string $pluginId): self;

  /**
   * Gets the plugin's stored settings.
   *
   * @return array
   *   The settings.
   */
  public function getPluginSettings(): array;

  /**
   * Sets the plugin's stored settings.
   *
   * @param array $pluginSettings
   *   The settings.
   *
   * @return self
   *   The wrapper, for chaining.
   */
  public function setPluginSettings(array $pluginSettings): self;

  /**
   * Gets the configured plugin instance.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginInterface|null
   *   The instance, or NULL when no plugin id is set or the id names a
   *   definition the manager does not have.
   */
  public function getPlugin(): ?ConfiguredPluginInterface;

  /**
   * Returns the configured plugin's settings summary.
   *
   * @return array
   *   Lines describing what is configured.
   */
  public function settingsSummary(): array;

  /**
   * Returns the wrapper as it is stored on the component.
   *
   * @return array
   *   The stored representation.
   */
  public function toArray(): array;

}
