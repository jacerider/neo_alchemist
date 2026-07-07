<?php

/**
 * @file
 * Hooks provided by the Neo | Alchemist module.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\neo_alchemist\Entity\Component;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the render build and cacheability of every Neo Alchemist component.
 *
 * Invoked once per component instance as its render array is built in
 * \Drupal\neo_alchemist\Entity\Component::toRenderable(), before any Alchemist
 * preview wrapper is applied, so $build['#props'] holds the raw component
 * props. It only runs on a cache miss (the component build is not itself
 * render-cached).
 *
 * Both module implementations and the active theme chain's implementations are
 * invoked (modules first, then the active theme). Because components are
 * authored in themes, a theme's own .theme file is the natural home for a
 * component-specific alter; use a module for cross-cutting concerns that apply
 * regardless of the active theme.
 *
 * Because this fires for EVERY component on the page, prefer the
 * component-specific variant hook_neo_component_build_BASE_ID_alter() whenever
 * you only care about one component — it runs no code for the others.
 *
 * To change cacheability, mutate $cacheability; do NOT write to
 * $build['#cache'] directly, as it is overwritten when the component's
 * cacheable metadata is applied to the build.
 *
 * @param array $build
 *   The component render array: a '#type' => 'component' element with '#props'
 *   and (optionally) '#slots'.
 * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
 *   The component's cacheable metadata. Add cache tags/contexts or tighten
 *   max-age here; it is applied to $build and bubbles up to the page.
 * @param \Drupal\neo_alchemist\Entity\Component $component
 *   The component config entity being rendered. Use $component->isPreview() to
 *   detect the Alchemist editor preview and $component->getComponentId() for
 *   the SDC id (e.g. "front:bottom").
 *
 * @see \Drupal\neo_alchemist\Entity\Component::toRenderable()
 * @see hook_neo_component_build_BASE_ID_alter()
 */
function hook_neo_component_build_alter(array &$build, CacheableMetadata $cacheability, Component $component): void {
  // Example: tag every component with the config entity it was built from so
  // editing the component invalidates rendered pages.
  $cacheability->addCacheTags(['neo_component:' . $component->id()]);
}

/**
 * Alter the build and cacheability of one specific Neo Alchemist component.
 *
 * This is the same as hook_neo_component_build_alter() but scoped to a single
 * component, and is the performance-friendly way to target one: it runs only
 * for that component, not for every component on the page.
 *
 * BASE_ID is the component's SDC id with every run of non-alphanumeric
 * characters replaced by a single underscore. For "front:bottom" the hook is
 * hook_neo_component_build_front_bottom_alter().
 *
 * @param array $build
 *   The component render array.
 * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
 *   The component's cacheable metadata.
 * @param \Drupal\neo_alchemist\Entity\Component $component
 *   The component config entity being rendered.
 *
 * @see hook_neo_component_build_alter()
 */
function hook_neo_component_build_BASE_ID_alter(array &$build, CacheableMetadata $cacheability, Component $component): void {
  // Example: the component prints the current year, so keep pages that include
  // it cacheable only until the calendar year turns over. Pages still cache all
  // year, then rebuild once at the boundary.
  $now = new DrupalDateTime('now');
  $next_year = new DrupalDateTime(($now->format('Y') + 1) . '-01-01 00:00:00');
  $seconds = $next_year->getTimestamp() - $now->getTimestamp();
  $cacheability->setCacheMaxAge(Cache::mergeMaxAges($cacheability->getCacheMaxAge(), $seconds));
}

/**
 * @} End of "addtogroup hooks".
 */
