<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Core\Template\Attribute;

/**
 * Hands back the value the component is rendered with.
 *
 * Separate from resolving a value because rendering may do things SDC cannot
 * take — the render value is allowed to diverge from the schema-valid one, and
 * a required prop with nothing to show needs a stand-in rather than an
 * exception.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapeValueInterface
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeRenderInterface extends ComponentShapeIdentityInterface {

  /**
   * Get the prop value that will be sent to the component for rendering.
   *
   * This differs from getValue() in that modifications can be done here that
   * are not compatible with SDC.
   *
   * Works on any shape, root or nested: the attributes are the whole of what
   * makes this a render, and they are passed in. They used to be stashed on the
   * shape while the predicate that read them read the *root* shape's, so this
   * call on a child wrote a flag nobody read and silently did nothing.
   *
   * @param \Drupal\Core\Template\Attribute $attributes
   *   The attribute that will be applied to the component wrapper. Container
   *   shapes hand this same object to their children, so a nested shape can
   *   contribute to the wrapper — which is how a style prop applies classes.
   *
   * @return mixed
   *   The prop value.
   *
   * @see \Drupal\neo_alchemist\Shape\ComponentShapeValueInterface::getValue()
   */
  public function getPropValue(Attribute $attributes): mixed;

  /**
   * Get a stand-in value to render a required prop with during preview.
   *
   * A required prop with no `examples` has no value in a preview, so it is
   * dropped from the props passed to SDC, which trips SDC's required-prop
   * schema assertion and takes the whole preview down. This lets a shape hand
   * back something schema-valid to render in its place instead.
   *
   * This exists so `examples` does not have to be pressed into service as
   * preview data: `examples` feeds getDefaultSchemaValue() and so becomes the
   * live fallback value too, which would quietly satisfy a prop that content
   * builders are supposed to be forced to fill in.
   *
   * Only consulted when the component is in preview and the shape is required
   * and empty, so it can never leak into a live render. The content-builder
   * requirement is enforced separately by form validation.
   *
   * @return mixed
   *   A render-ready value in the same form getPropValue() would return, or
   *   NULL if the shape cannot produce one — in which case the preview shows a
   *   notice naming the unsatisfied prop instead of rendering the component.
   *
   * @see \Drupal\neo_alchemist\ComponentInterface::getPropValues()
   * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface::validateForm()
   */
  public function getPreviewPlaceholder(): mixed;

}
