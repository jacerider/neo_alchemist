<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Component\Render\MarkupInterface;

/**
 * Names a shape.
 *
 * The one role every other extends, so a caller holding any role can say which
 * prop it was working on without widening to the whole shape. `id()` is the
 * nested id — the shape's address in its tree — and is what keys options,
 * form elements and error messages.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeIdentityInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * The shape's address: its parent's id, its own name, and its delta.
   *
   * Segments are joined with a tilde, and a delta follows the name of the
   * shape that is one of an iterable's rows — so a heading in the second row
   * of `items` is `items~heading~1` and its own title is
   * `items~heading~1~title`. Each ancestor's delta therefore sits at its own
   * depth rather than all of them collecting at the end, which keeps a
   * shape's id a prefix of every descendant's.
   *
   * @param bool $ignoreDelta
   *   Drop every delta, this shape's and its ancestors' alike, leaving the
   *   pure name path (`items~heading~title`). This is the structural address
   *   the config side uses — the `expression` string, the saved plugin and
   *   expansion settings, the prop form's tabs — none of which vary by row.
   *
   * @return string
   *   The shape id.
   */
  public function id(bool $ignoreDelta = FALSE): string;

  /**
   * Get the prop name.
   *
   * This is the machine name of the prop.
   *
   * @return string
   *   The prop name.
   */
  public function getName(): string;

  /**
   * Get the prop title.
   *
   * This is the user-facing title of the prop.
   *
   * @return string
   *   The prop title.
   */
  public function getTitle(): string|MarkupInterface;

}
