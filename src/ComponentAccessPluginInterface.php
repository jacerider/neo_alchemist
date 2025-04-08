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

}
