<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines a component access.
 */
class ComponentAccess implements ComponentAccessInterface {

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
   * {@inheritdoc}
   */
  public function setPluginId(string $pluginId): self {
    if ($this->pluginId !== $pluginId) {
      $this->plugin = NULL;
      $this->pluginSettings = [];
    }
    $this->pluginId = $pluginId;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->pluginId;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSettings(): array {
    return $this->pluginSettings;
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginSettings(array $pluginSettings): self {
    if ($this->pluginSettings !== $pluginSettings) {
      $this->plugin = NULL;
    }
    $this->pluginSettings = $pluginSettings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugin(): ?ComponentAccessPluginInterface {
    if (!isset($this->plugin)) {
      $this->plugin = NULL;
      $pluginId = $this->getPluginId();
      if ($pluginId && $this->manager->hasDefinition($pluginId)) {
        $this->plugin = $this->manager->createInstance($pluginId, [
          'access' => $this,
          'settings' => $this->getPluginSettings(),
        ]);
      }
    }
    return $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function isNew(): bool {
    return empty($this->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer neo_alchemist')) {
      return AccessResult::allowed();
    }
    if ($plugin = $this->getPlugin()) {
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
