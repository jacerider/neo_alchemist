<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Binding;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Decides which properties of a resolved field carry human-readable text.
 *
 * Driven by each field item's own property definitions, so a field type works
 * here without registering anything: a property typed as a string is treated as
 * text. That is what lets a custom field carrying text under *two* properties —
 * a label as well as a value — be read correctly without anyone having thought
 * of it in advance.
 *
 * The two lists below are corrections for the cases where the data type lies. A
 * list field's `value` really is a string; it just holds a machine name from a
 * fixed vocabulary. There is no way to tell from the definition alone.
 *
 * Unlike shapes and value plugins, which declare their own behaviour on
 * themselves, this axis has to keep a central table: field types belong to
 * Drupal and to whichever module defines them, and none of them can be asked to
 * implement an interface from this one. The alter hook is the seam instead.
 *
 * @see hook_neo_alchemist_search_field_text_policy_alter()
 */
final class FieldTextPolicy {

  /**
   * Field types whose main property is an identifier rather than prose.
   *
   * These need barring at the type level because their properties are
   * genuinely string-typed: a list field's `value` is a string, it just
   * happens to be a machine name from a fixed vocabulary.
   */
  private const BARRED_TYPES = [
    'list_string' => TRUE,
    'list_integer' => TRUE,
    'list_float' => TRUE,
    'boolean' => TRUE,
    'entity_reference' => TRUE,
    'entity_reference_revisions' => TRUE,
    'file' => TRUE,
    'image' => TRUE,
    'datetime' => TRUE,
    'daterange' => TRUE,
    'timestamp' => TRUE,
    'created' => TRUE,
    'changed' => TRUE,
    'path' => TRUE,
    'password' => TRUE,
    'language' => TRUE,
    'uri' => TRUE,
    'uuid' => TRUE,
    'map' => TRUE,
    // Reading one component tree while extracting another would either
    // duplicate the authored half or recurse.
    'neo_component_tree' => TRUE,
  ];

  /**
   * Property names that are structure whatever their data type says.
   */
  private const BARRED_PROPERTIES = [
    'format' => TRUE,
    'langcode' => TRUE,
    'uri' => TRUE,
    'options' => TRUE,
    'target_id' => TRUE,
    'target_uuid' => TRUE,
    'target_type' => TRUE,
    'target_revision_id' => TRUE,
    'entity' => TRUE,
    'display' => TRUE,
    'width' => TRUE,
    'height' => TRUE,
    'country_code' => TRUE,
    'sorting_code' => TRUE,
    '_attributes' => TRUE,
  ];

  /**
   * The barred lists, after modules have had their say.
   *
   * @var array{types: array<string, true>, properties: array<string, true>}|null
   */
  private ?array $barred = NULL;

  /**
   * Constructs a FieldTextPolicy.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Invokes the alter hook that lets a module correct these lists for a field
   *   type this one has never heard of.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Whether a field type may contribute text at all.
   */
  public function allowsType(string $fieldType): bool {
    return !isset($this->barred()['types'][$fieldType]);
  }

  /**
   * The barred types and properties, alterable once per request.
   *
   * @return array{types: array<string, true>, properties: array<string, true>}
   *   The two lists.
   */
  private function barred(): array {
    if ($this->barred === NULL) {
      $barred = [
        'types' => self::BARRED_TYPES,
        'properties' => self::BARRED_PROPERTIES,
      ];
      $this->moduleHandler->alter('neo_alchemist_search_field_text_policy', $barred);
      $this->barred = $barred + ['types' => [], 'properties' => []];
    }
    return $this->barred;
  }

  /**
   * Whether one property of a field item carries human-readable text.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The property's data definition.
   * @param string $name
   *   The property name.
   *
   * @return bool
   *   TRUE when the property should be indexed.
   */
  public function isTextProperty(DataDefinitionInterface $definition, string $name): bool {
    // A computed property means running something to get it — a text format
    // pipeline, most often — and its uncomputed sibling is already indexed.
    if ($definition->isComputed() || $definition->isInternal()) {
      return FALSE;
    }
    if (isset($this->barred()['properties'][$name])) {
      return FALSE;
    }
    $dataType = $definition->getDataType();
    return $dataType === 'string'
      || $dataType === 'text'
      || str_starts_with($dataType, 'string_')
      || str_starts_with($dataType, 'text_');
  }

}
