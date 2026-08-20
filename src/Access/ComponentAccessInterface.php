<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface;

/**
 * A configured access rule stored on a component.
 *
 * Everything about being "a plugin id plus settings under a uuid" is inherited;
 * what is this family's own is the operation vocabulary and ::access().
 */
interface ComponentAccessInterface extends ConfiguredPluginWrapperInterface {

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
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Access\ComponentAccessPluginInterface|null
   *   The access plugin instance, or NULL when none is configured.
   */
  public function getPlugin(): ?ComponentAccessPluginInterface;

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
