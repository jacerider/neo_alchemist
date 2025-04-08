<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for neo_component_shape plugins.
 */
interface ComponentAccessInterface {

  /**
   * The available operations for component access.
   *
   * @var array
   */
  const OPS = [
    'view' => [
      'label' => 'View',
      'description' => 'View the component on the frontend.',
    ],
    'update' => [
      'label' => 'Update',
      'description' => 'Update the component in the backend.',
    ],
    'create' => [
      'label' => 'Create or Remove',
      'description' => 'Create or remove a component in the backend.',
    ],
  ];

  /**
   * Gets the label (title) of the component access.
   *
   * @return string
   *   The label of the access.
   */
  public function label(): string;

  /**
   * Gets the UUID of the component access.
   *
   * @return string|null
   *   The UUID or null if not set.
   */
  public function uuid(): ?string;

  /**
   * Returns the summarized configuration of the access plugin.
   *
   * @return array
   *   An array of summarized configuration of the access plugin.
   */
  public function settingsSummary(): array;

  /**
   * Gets the component of the component access.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Gets the plugin ID of the component access.
   *
   * @return string
   *   The plugin ID.
   */
  public function getPluginId(): string;

  /**
   * Sets the plugin ID of the component access.
   *
   * @param string $pluginId
   *   The plugin ID to set.
   *
   * @return self
   *   Returns the instance for method chaining.
   */
  public function setPluginId(string $pluginId): self;

  /**
   * Retrieves the plugin settings.
   *
   * @return array
   *   An array of plugin settings.
   */
  public function getPluginSettings(): array;

  /**
   * Sets the plugin settings.
   *
   * @param array $pluginSettings
   *   An associative array of plugin settings.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function setPluginSettings(array $pluginSettings): self;

  /**
   * Retrieves the plugin instance.
   *
   * This method checks if the plugin instance is already set. If not, it
   * attempts to create an instance of the plugin using the plugin ID and
   * component settings. If the plugin ID is valid and the manager has a
   * definition for it, the plugin instance is created and stored.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentAccessPluginInterface|null
   *   The plugin instance if available, or NULL if it could not be created.
   */
  public function getPlugin(): ?ComponentAccessPluginInterface;

  /**
   * Determines if the component access is new.
   *
   * @return bool
   *   TRUE if the component access is new, FALSE otherwise.
   */
  public function isNew(): bool;

  /**
   * Check access.
   *
   * @param string $op
   *   The operation to check access for, e.g., 'view', 'edit', 'delete'.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result object indicating whether the operation is allowed
   *   or denied for the specified user account.
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface;

  /**
   * Converts the component access to an array.
   *
   * @return array
   *   An associative array representing the access.
   */
  public function toArray(): array;

}
