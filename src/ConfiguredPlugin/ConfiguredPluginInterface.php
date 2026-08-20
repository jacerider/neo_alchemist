<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * A plugin that is picked, configured and stored on a component.
 *
 * The access, filter and slot families each declared these three members for
 * themselves. They are the surface the shared admin machinery uses — the add
 * picker calls ::isApplicable() to decide whether to offer a plugin at all,
 * and the list rows call ::label() and ::settingsSummary() to describe one.
 *
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginManagerBase
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface
 */
interface ConfiguredPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the summarized configuration of the plugin.
   *
   * @return array
   *   Lines describing what the plugin is configured to do.
   */
  public function settingsSummary(): array;

  /**
   * Whether this plugin can be configured on the given component.
   *
   * The manager base calls this when it narrows definitions to what a
   * component supports, which is how the add picker is built: returning
   * FALSE removes the plugin from the list a site builder is offered.
   *
   * A rule already saved against a component whose plugin later turns
   * non-applicable still executes, so a plugin must degrade gracefully in
   * that case rather than assuming its preconditions hold.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component the plugin would be attached to.
   *
   * @return bool
   *   TRUE when the plugin is offered for this component.
   */
  public static function isApplicable(ComponentInterface $component): bool;

}
