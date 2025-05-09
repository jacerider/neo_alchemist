<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessInterface;
use Drupal\neo_alchemist\ComponentAccessPluginBase;
use Drupal\neo_icon\IconTranslationTrait;
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
final class PermissionAccess extends ComponentAccessPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;
  use IconTranslationTrait;

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
  public function defaultConfiguration() {
    return [
      'ops' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    // $roles = $this->getRoles();
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $config = $this->configuration['ops'][$op] ?? [];
      if ($config) {
        $summary[] = strtoupper($info['label']);
        $summary[] = '- ' . $this->t('Permissions: @permissions', [
          '@permissions' => '[' . implode('], [', $config['permissions']) . ']',
        ]);
        $summary[] = '- ' . $this->t('Match: @match', [
          '@match' => $config['match'] === 'any' ? $this->t('Any') : $this->t('All'),
        ]);
      }
    }

    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $permissionOptions = $this->getPermissionsAsOptions();

    $form['ops'] = [
      '#type' => 'container',
    ];
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $row = [
        '#type' => 'details',
        '#title' => $this->adminIcon($info['label']),
        '#description' => $info['description'],
        '#description_display' => 'before',
        '#open' => !empty($this->configuration['ops'][$op]),
      ];
      $row['permissions'] = [
        '#type' => 'select',
        '#title' => $this->t('Permissions'),
        '#options' => $permissionOptions,
        '#default_value' => $this->configuration['ops'][$op]['permissions'] ?? [],
        '#multiple' => TRUE,
      ];
      $row['match'] = [
        '#type' => 'radios',
        '#title' => $this->t('Match'),
        '#options' => [
          'any' => $this->t('Has any of the selected roles'),
          'all' => $this->t('Has all of the selected roles'),
        ],
        '#default_value' => $this->configuration['ops'][$op]['match'] ?? 'any',
        '#required' => TRUE,
      ];
      $form['ops'][$op] = $row;
    }
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $permissions = array_filter($form_state->getValue(['ops', $op, 'permissions'], []));
      if ($permissions) {
        $form_state->setValue(['ops', $op, 'permissions'], $permissions);
      }
      else {
        $form_state->unsetValue(['ops', $op]);
      }
    }
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

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    if (!empty($this->configuration['ops'][$op])) {
      $config = $this->configuration['ops'][$op];
      $access = AccessResult::allowedIfHasPermissions($account, $config['permissions'], $config['match'] === 'any' ? 'OR' : 'AND');
      return AccessResult::forbiddenIf(!$access->isAllowed())->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

}
