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
   * Retrieves the nested ID by concatenating the elements of the parent path.
   *
   * Each parent ID is separated by a period (.).
   *
   * @param bool $ignoreDelta
   *   Whether to ignore the delta when generating the ID.
   *
   * @return string
   *   The concatenated parent ID.
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
