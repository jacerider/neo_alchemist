<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperTrait;

/**
 * Defines a component access.
 */
class ComponentAccess implements ComponentAccessInterface {

  use ConfiguredPluginWrapperTrait;
  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The access manager.
   *
   * @var \Drupal\neo_alchemist\ComponentAccessPluginManager
   */
  protected $manager;

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $component;

  /**
   * The uuid.
   *
   * @var string|null
   */
  protected $uuid;

  /**
   * The plugin id.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * The plugin settings.
   *
   * @var array
   */
  protected $pluginSettings;

  /**
   * The access plugin.
   *
   * @var \Drupal\neo_alchemist\ComponentAccessPluginInterface
   */
  protected $plugin;

  /**
   * Constructs a new ComponentSlot object.
   */
  public function __construct(ComponentAccessPluginManager $manager, ComponentInterface $component, $settings = []) {
    $this->manager = $manager;
    $this->component = $component;
    $this->uuid = $settings['uuid'] ?? NULL;
    $this->pluginId = $settings['plugin_id'] ?? '';
    $this->pluginSettings = $settings['plugin_settings'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    if ($plugin = $this->getPlugin()) {
      return $plugin->label();
    }
    return $this->title ?? 'Undefined';
  }

  /**
   * {@inheritdoc}
   */
  public function uuid(): ?string {
    return $this->uuid ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    if ($plugin = $this->getPlugin()) {
      return $plugin->settingsSummary();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugin(): ?ComponentAccessPluginInterface {
    return $this->loadPlugin('access');
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    $plugin = $this->getPlugin();
    // Administrators bypass access plugins, unless a plugin opts to be enforced
    // even for administrators for this operation (e.g. a content-presence gate
    // that must hide the component on the frontend for everyone).
    if ($account->hasPermission('administer neo_alchemist') && (!$plugin || $plugin->bypassAdminAccess($op))) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($plugin) {
      return $plugin->access($op, $account);
    }
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'plugin_id' => $this->getPluginId(),
      'plugin_settings' => $this->getPluginSettings(),
    ];
  }

}
