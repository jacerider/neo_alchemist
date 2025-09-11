<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;

/**
 * Defines a component slot.
 */
class ComponentSlot implements ComponentSlotInterface {

  use DependencySerializationTrait;

  /**
   * The slot manager.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotPluginManager
   */
  protected $manager;

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $component;

  /**
   * The slot name.
   *
   * @var string
   */
  protected $name;

  /**
   * The slot schema.
   *
   * @var array
   */
  protected $schema;

  /**
   * The slot settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * The slot plugins.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotPluginInterface[]
   */
  protected $plugins;

  /**
   * Constructs a new ComponentSlot object.
   */
  public function __construct(ComponentSlotPluginManager $manager, ComponentInterface $component, string $name, array $schema, array $settings) {
    $this->manager = $manager;
    $this->component = $component;
    $this->name = $name;
    $this->schema = $schema;
    $this->settings = $settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritDoc}
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * {@inheritDoc}
   */
  public function getSchema(): array {
    return $this->schema;
  }

  /**
   * {@inheritDoc}
   */
  public function getTitle(): string {
    return $this->schema['title'] ?? 'Unnamed Slot';
  }

  /**
   * {@inheritDoc}
   */
  public function getDescription(): string {
    return $this->schema['description'] ?? 'Unnamed Slot';
  }

  /**
   * {@inheritDoc}
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugins(): array {
    if (!isset($this->plugins)) {
      $this->plugins = [];
      foreach ($this->settings['plugins'] ?? [] as $uuid => $data) {
        if ($this->manager->hasDefinition($data['plugin'])) {
          $this->plugins[$uuid] = $this->manager->createInstance($data['plugin'], [
            'component' => $this->component,
            'uuid' => $uuid,
            'settings' => $data['settings'] ?? [],
          ]);
        }
      }
    }
    return $this->plugins;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugin(string $uuid): ?ComponentSlotPluginInterface {
    return $this->getPlugins()[$uuid] ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function addPlugin(string $plugin_id, $settings = []): ComponentSlotPluginInterface {
    $plugins = $this->getPlugins();
    $plugin = $this->manager->createInstance($plugin_id, [
      'component' => $this->component,
      'uuid' => \Drupal::service('uuid')->generate(),
      'settings' => $settings,
    ]);
    $this->plugins = $plugins + [
      $plugin->uuid() => $plugin,
    ];
    return $plugin;
  }

  /**
   * {@inheritDoc}
   */
  public function removePlugin(string $uuid): self {
    unset($this->plugins[$uuid]);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function toArray(): array {
    $settings = $this->getSettings();
    $settings['plugins'] = array_map(fn ($plugin) => [
      'plugin' => $plugin->getPluginId(),
      'settings' => $plugin->getConfiguration(),
    ], $this->getPlugins());
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $build = [];
    foreach ($this->getPlugins() as $plugin) {
      $build[$plugin->uuid()] = $plugin->toRenderable();
    }
    return array_filter($build);
  }

}
