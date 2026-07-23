<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for neo_component_access plugins.
 */
interface ComponentAccessPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the summarized configuration of the access plugin.
   *
   * @return array
   *   An array of summarized configuration of the access plugin.
   */
  public function settingsSummary(): array;

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
   * Whether administrators bypass this access check for a given operation.
   *
   * Users with the "administer neo_alchemist" permission normally bypass all
   * access plugins. A plugin can return FALSE for an operation to be enforced
   * even for administrators (e.g. a content-presence gate that should hide the
   * component on the frontend for everyone).
   *
   * @param string $op
   *   The operation being checked (see ComponentAccessInterface::OPS).
   *
   * @return bool
   *   TRUE if administrators bypass this plugin for the operation (the
   *   default), FALSE to enforce the check even for administrators.
   */
  public function bypassAdminAccess(string $op): bool;

}
