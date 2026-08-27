<?php

/**
 * @file
 * Hooks provided by the Neo Alchemist Search module.
 */

declare(strict_types=1);

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter which field types and properties count as human-readable text.
 *
 * Whether a field property is text is worked out from its data definition: a
 * property typed as a string is text. That handles field types nobody here has
 * heard of, including custom ones carrying words under more than one property.
 *
 * These two lists are corrections for the cases where the definition lies. A
 * list field's `value` really is typed as a string; it just holds a machine
 * name from a fixed vocabulary, and there is no way to tell the two apart.
 *
 * This is the one classification in this module that lives in a central table
 * rather than being declared by the thing it describes. Component shapes and
 * value plugins each declare their own behaviour, because they belong to this
 * suite. Field types do not — they belong to Drupal and to whichever module
 * defines them, and none of those can be asked to implement an interface from
 * here. So this hook is the seam instead.
 *
 * @param array $barred
 *   Two lists, each keyed by name with TRUE as the value:
 *   - 'types': field types that never contribute text, whatever their
 *     properties are typed as.
 *   - 'properties': property names that are structure rather than content,
 *     whatever their data type says.
 *
 * @see \Drupal\neo_alchemist_search\Binding\FieldTextPolicy
 */
function hook_neo_alchemist_search_field_text_policy_alter(array &$barred): void {
  // Example: a colour field stores its value as a string, but a hex code is
  // not something anyone searches for.
  $barred['types']['color_field_type'] = TRUE;

  // Example: this site's rating field keeps an editor's private note in a
  // property that should not reach the index.
  $barred['properties']['internal_note'] = TRUE;
}

/**
 * @} End of "addtogroup hooks".
 */
