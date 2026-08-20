<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Access\ComponentAccessInterface;
use Drupal\neo_alchemist\Access\ComponentAccessOpsMatchPluginBase;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\user\PermissionHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_access.
 */
#[ComponentAccess(
  id: 'permission',
  label: new TranslatableMarkup('Permission'),
  description: new TranslatableMarkup('Check if the user has specific permission(s).'),
)]
final class PermissionAccess extends ComponentAccessOpsMatchPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The permission handler.
   *
   * @var \Drupal\user\PermissionHandlerInterface
   */
  protected PermissionHandlerInterface $permissionHandler;

  /**
   * Module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentAccessInterface $access,
    array $configuration,
    PermissionHandlerInterface $permission_handler,
    ModuleExtensionList $module_extension_list,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $access, $configuration);
    $this->permissionHandler = $permission_handler;
    $this->moduleExtensionList = $module_extension_list;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['access'],
      $configuration['settings'],
      $container->get('user.permissions'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getValueKey(): string {
    return 'permissions';
  }

  /**
   * {@inheritdoc}
   */
  protected function getValueLabel(): string {
    return 'Permissions';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildSelectionElement(array $default): array {
    return [
      '#type' => 'select',
      '#title' => $this->t('Permissions'),
      '#options' => $this->getPermissionsAsOptions(),
      '#default_value' => $default,
      '#multiple' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function summarizeSelection(array $selected): string {
    return '[' . implode('], [', $selected) . ']';
  }

  /**
   * {@inheritdoc}
   */
  protected function accountMatches(AccountInterface $account, array $selected, string $match): bool {
    return AccessResult::allowedIfHasPermissions($account, $selected, $match === 'any' ? 'OR' : 'AND')->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  protected function getForbiddenReason(): string {
    return 'You do not have the required permissions to access this content.';
  }

  /**
   * {@inheritdoc}
   */
  protected function getAccessCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * Returns a list of available permissions.
   *
   * @return array
   *   An associative array of permission IDs and labels.
   */
  protected function getPermissionsAsOptions() {
    $perms = [];
    $permissions = $this->permissionHandler->getPermissions();
    foreach ($permissions as $perm => $perm_item) {
      $provider = $perm_item['provider'];
      $display_name = $this->moduleExtensionList->getName($provider);
      $perms[$display_name][$perm] = strip_tags((string) $perm_item['title']);
    }
    return $perms;
  }

}
