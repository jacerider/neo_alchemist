<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;

/**
 * A source that contributes field choices of its own to the mapping.
 *
 * Most sources yield entities and stop — every choice a child shape can be
 * bound to is then either a field on those entities or one of the mapper's own
 * pseudo fields. A views source is the exception: a view can render a column
 * that is not a field on the row's entity at all, so it both adds `_view:*`
 * choices to the picker and knows how to read them back.
 *
 * The mapper owns its built-in pseudo fields and asks the source about any
 * other underscore-prefixed choice.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatchMapper
 */
interface ChildrenMatchFieldSourceInterface extends ChildrenMatchSourceInterface {

  /**
   * Adds source-specific choices to one child's field picker.
   *
   * @param array $options
   *   The grouped options, by reference.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The child shape being configured.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, carrying whatever buildChildrenMatchSourceForm() stashed.
   */
  public function alterChildrenMatchOptions(array &$options, ComponentShapePluginInterface $shape, FormStateInterface $form_state): void;

  /**
   * The underscore-stripped prefixes this source reads.
   *
   * Only these reach fetchChildrenMatchField(). Every other underscore choice
   * is either one of the mapper's own pseudo fields or a key the field matcher
   * understands — `_entity:label` being the common one, which a views mapping
   * uses precisely because it works on rows of any entity type. Claiming
   * prefixes wholesale would swallow those.
   *
   * @return string[]
   *   The prefixes, e.g. ['view'].
   */
  public function getChildrenMatchFieldPrefixes(): array;

  /**
   * Reads one of those choices for an iterated entity.
   *
   * @param string $prefix
   *   The choice's underscore-stripped prefix — `view` for `_view:title`. Only
   *   prefixes named by getChildrenMatchFieldPrefixes() are passed.
   * @param \Drupal\neo_alchemist\ChildrenMatchField $field
   *   The child being filled.
   *
   * @return mixed
   *   The value, or NULL to leave the child empty.
   */
  public function fetchChildrenMatchField(string $prefix, ChildrenMatchField $field): mixed;

}
