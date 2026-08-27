<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

/**
 * A value plugin that reads fields from the entity a component renders against.
 *
 * Implement this when a plugin's configuration names entity fields, so that
 * code which needs to know what a layout draws on can ask rather than guess.
 * A layout is often shared by every entity of a bundle while its visible text
 * differs per entity, and this is the only thing that explains where that text
 * comes from without rendering the page to find out.
 *
 * Not implementing it is itself a declaration: the plugin reads no host-entity
 * field. Providers of site-wide settings, menus, breadcrumbs and views results
 * all belong in that group — what they produce is either the same everywhere
 * or belongs to some other entity.
 *
 * The method is static and takes the stored settings because callers work from
 * cached plugin definitions. Constructing a value plugin requires a shape,
 * which requires a loaded component and a host entity, and the callers this
 * exists for — indexing, dependency analysis, usage reporting — run over every
 * entity on a site and cannot afford that. This mirrors
 * ComponentValuePluginInterface::isApplicable(), which the plugin manager calls
 * the same way.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::isApplicable()
 */
interface ComponentValueFieldSourceInterface {

  /**
   * The entity fields this plugin reads, given a stored configuration.
   *
   * Keys are in the field-matcher grammar: dot-separated hops, each
   * `fieldName[:property[:subProperty]]`, so a plugin that follows a reference
   * returns the whole path rather than just the field it lands on.
   *
   * Return only fields whose value the plugin actually reads. A field name
   * used to pick a formatter, or a setting that merely gates whether another
   * field is shown, is not a source.
   *
   * @param array $settings
   *   The plugin's stored settings, as they appear in a component's
   *   `settings.props.<prop>.plugins.<shape>.<plugin>.settings`.
   *
   * @return string[]
   *   Field keys, in no particular order. Empty when this configuration reads
   *   nothing — a plugin may be a field source in principle and not in a
   *   particular placement.
   *
   * @see \Drupal\neo_alchemist\Match\MatcherField::getEntityField()
   */
  public static function getSourceFieldKeys(array $settings): array;

}
