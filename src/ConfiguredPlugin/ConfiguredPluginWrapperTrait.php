<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

/**
 * Resolves a plugin id and settings pair into a memoised plugin instance.
 *
 * ComponentAccess and ComponentFilter are the same object wearing two labels: a
 * uuid, a plugin id, that plugin's settings, and a lazily built instance of it
 * that has to be dropped whenever either input changes. Both had their own copy
 * of that, so "a configured plugin instance answers to its id and settings" was
 * owned twice and could drift — the two differ only in which manager they ask
 * and what the plugin calls its owner.
 *
 * The using class keeps the state, so its `@var` docblocks stay specific to the
 * plugin family it wraps rather than widening to a shared supertype. It must
 * declare `$manager`, `$pluginId`, `$pluginSettings` and `$plugin`, and provide
 * a `uuid()` method.
 *
 * ::getPlugin() stays with the using class because its return type names that
 * family, and a trait cannot widen a return type an interface has narrowed. The
 * class implements it as a one-line call to ::loadPlugin().
 */
trait ConfiguredPluginWrapperTrait {

  /**
   * {@inheritdoc}
   */
  public function setPluginId(string $pluginId): self {
    if ($this->pluginId !== $pluginId) {
      // A different plugin cannot be configured by the outgoing plugin's
      // settings, so they go with it rather than being handed to the new one.
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
      // The memoised instance was built with the outgoing settings baked in.
      $this->plugin = NULL;
    }
    $this->pluginSettings = $pluginSettings;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isNew(): bool {
    return empty($this->uuid());
  }

  /**
   * Builds and memoises the configured plugin instance.
   *
   * @param string $ownerKey
   *   The configuration key the plugin family reads this wrapper back out of —
   *   `access` or `filter`.
   *
   * @return object|null
   *   The plugin instance, or NULL when no plugin id is set or the id names a
   *   definition the manager does not have. Callers narrow this to their own
   *   plugin interface.
   */
  protected function loadPlugin(string $ownerKey): ?object {
    if (!isset($this->plugin)) {
      $this->plugin = NULL;
      $pluginId = $this->getPluginId();
      if ($pluginId && $this->manager->hasDefinition($pluginId)) {
        $this->plugin = $this->manager->createInstance($pluginId, [
          $ownerKey => $this,
          'settings' => $this->getPluginSettings(),
        ]);
      }
    }
    return $this->plugin;
  }

}
