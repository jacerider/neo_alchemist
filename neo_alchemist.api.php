<?php

/**
 * @file
 * Hooks provided by the Neo | Alchemist module.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
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
 * Alter each item produced by the "menu" component value provider.
 *
 * Invoked once per menu item (recursively, children included) as
 * \Drupal\neo_alchemist\Plugin\ComponentValue\MenuValue::buildItems() maps the
 * built menu tree onto the `menu` prop shape. $entry already carries the
 * documented item keys (title, description, icon, url, below) plus the
 * runtime menu-state flags (in_active_trail, is_expanded, is_collapsed).
 *
 * Any extra keys added here survive into the component prop and are available
 * in the component's twig — the menu item JSON schema does not forbid
 * additional properties. Set $entry to NULL to drop the item from the value
 * entirely.
 *
 * Add cacheability for anything the outcome depends on via
 * $shape->addCacheableDependency(); it bubbles into the component build.
 *
 * @param array|null $entry
 *   The mapped menu item value. Keys: title, description, icon,
 *   in_active_trail, is_expanded, is_collapsed, url (title/uri/options) and,
 *   when the item has children, below (nested items of the same shape,
 *   already altered). Set to NULL to drop the item.
 * @param array $item
 *   The source item from MenuLinkTree::build()'s #items, including
 *   original_link (\Drupal\Core\Menu\MenuLinkInterface) and the
 *   \Drupal\Core\Url object under 'url'. Treat as read-only context.
 * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
 *   The shape the value is being provided for; use it to add cacheable
 *   dependencies.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MenuValue
 */
function hook_neo_alchemist_menu_value_item_alter(?array &$entry, array $item, ComponentShapePluginInterface $shape): void {
  // Example: expose a per-link option (stored in the link's options array,
  // e.g. by a form alter on the menu link edit form) to the component twig.
  if (!empty($entry['url']['options']['badge'])) {
    $entry['badge'] = $entry['url']['options']['badge'];
  }
}

/**
 * Alter the Alchemist component fields applicable to a specific entity.
 *
 * Invoked from neo_alchemist_entity_component_field_definitions() everywhere
 * Alchemist enumerates an entity's component tree fields: the entity "Layout"
 * route (redirect target and picker table), its title, and the field lookup
 * used for token/image resolution. It lets modules narrow which of an
 * entity's component tree fields apply to that particular entity — e.g.
 * neo_alchemist_taxonomy reduces a taxonomy term's level fields to the single
 * field matching the term's hierarchy level.
 *
 * Implementations must only REMOVE entries; adding definitions here is not
 * supported (rendering and access still operate on the entity's real fields).
 *
 * Note this hook does not affect rendering or route access by itself — hide
 * fields from display via field view access (hook_entity_field_access()) and
 * direct URLs to any field's editor keep working as an escape hatch.
 *
 * @param \Drupal\neo_alchemist\ComponentFieldConfigInterface[] $fieldDefinitions
 *   The component field definitions applicable to the entity, keyed by field
 *   name. Depending on the caller, the set may already be limited to fields
 *   that allow per-entity customization.
 * @param \Drupal\Core\Entity\ContentEntityInterface $entity
 *   The entity the fields belong to.
 *
 * @see neo_alchemist_entity_component_field_definitions()
 */
function hook_neo_alchemist_entity_component_fields_alter(array &$fieldDefinitions, ContentEntityInterface $entity): void {
  // Example: on taxonomy terms, keep only the field named for the term's
  // vocabulary.
  if ($entity->getEntityTypeId() === 'taxonomy_term') {
    $fieldDefinitions = array_intersect_key($fieldDefinitions, ['field_' . $entity->bundle() => TRUE]);
  }
}

/**
 * @} End of "addtogroup hooks".
 */
