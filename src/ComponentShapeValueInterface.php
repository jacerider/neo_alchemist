<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Template\Attribute;

/**
 * Resolves the prop's value.
 *
 * The role a ComponentValue plugin most often wants: what does this prop hold,
 * what would it hold by default, and is that empty. Resolution is layered —
 * the schema default, then the provider chain, then the parent value, then an
 * editor's override — and the getters here read the result of that pipeline
 * rather than re-running it.
 *
 * `resolveValue()` is called both during default assembly and just before
 * rendering, so implementations must be idempotent and must not depend on
 * `init()` having run.
 *
 * Reading only. The two values fed *into* that pipeline — ::setParentValue()
 * and ::setOverrideValue() — are on ComponentShapeSetupInterface, because
 * init() consumes both and neither means anything afterwards.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeSetupInterface
 * @see \Drupal\neo_alchemist\ComponentShapeRenderInterface
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeValueInterface extends ComponentShapeIdentityInterface {

  /**
   * Resolve a value into its final usable form.
   *
   * Called with the schema default during default-value assembly and with the
   * full value (stored or default) just before rendering, so implementations
   * MUST be idempotent. May be invoked on a shape that has not been
   * initialized, so implementations MUST NOT rely on the field item or any
   * init()-time state. The returned value must keep the schema's shape.
   * Container shapes (object/array) recurse into their children so every
   * nested shape gets a chance to resolve its own slice of the value.
   *
   * @param mixed $value
   *   The value to resolve.
   *
   * @return mixed
   *   The resolved value.
   */
  public function resolveValue(mixed $value): mixed;

  /**
   * Get the working prop value.
   *
   * This value should be able to be passed to the SDC.
   *
   * Pass the component's wrapper attributes to also run the pre-render stage,
   * which is what ::getPropValue() does and what container shapes pass down to
   * their children. Rendering is decided by this argument alone: there is no
   * mode held anywhere, so the same shape asked twice answers differently only
   * because it was asked differently.
   *
   * @param \Drupal\Core\Template\Attribute|null $renderAttributes
   *   The attributes that will be applied to the component wrapper when this
   *   value is being resolved for rendering, NULL when it is not.
   *
   * @return mixed
   *   The prop value.
   *
   * @see \Drupal\neo_alchemist\ComponentShapeRenderInterface::getPropValue()
   */
  public function getValue(?Attribute $renderAttributes = NULL): mixed;

  /**
   * Get the default value of the prop.
   *
   * @return mixed
   *   The default value provided by SDC.
   */
  public function getDefaultValue(): mixed;

  /**
   * Build the default value for the prop.
   *
   * This will return the default value ready for component usage.
   *
   * Takes the wrapper attributes for the same reason ::getValue() does: a child
   * whose "default" option is on reaches its value through here rather than
   * through ::getValue(), and it has to render on the same terms as its
   * siblings.
   *
   * @param \Drupal\Core\Template\Attribute|null $renderAttributes
   *   The attributes that will be applied to the component wrapper when this
   *   value is being built for rendering, NULL when it is not.
   *
   * @return mixed
   *   The built default value.
   */
  public function buildDefaultValue(?Attribute $renderAttributes = NULL): mixed;

  /**
   * Get the default defined in the schema.
   *
   * @return mixed
   *   The default value defined in the schema.
   */
  public function getDefaultSchemaValue(): mixed;

  /**
   * Retrieves the override value.
   *
   * @return mixed
   *   The override value, which can be of various types including array,
   *   string, integer, float, or boolean.
   */
  public function getOverrideValue(): mixed;

  /**
   * Checks if the component shape is empty.
   *
   * @return bool
   *   TRUE if the component shape is empty, FALSE otherwise.
   */
  public function isEmpty(): bool;

  /**
   * Determine whether a value produced by a provider is empty.
   *
   * Used by the value-provision contract to decide "found vs empty". A scalar
   * is empty only when NULL or the empty string — `0`, `'0'` and FALSE are
   * values. A composite discounts whichever keys the shape reports as
   * presentation rather than content, so a value carrying nothing but those
   * still counts as empty.
   *
   * Ask the shape that owns the value. Each shape names its own presentational
   * keys, so a parent testing a child's value must call this on the child.
   *
   * @param mixed $value
   *   The value threaded through the provider chain.
   *
   * @return bool
   *   TRUE if the value carries no content, FALSE otherwise.
   *
   * @see \Drupal\neo_alchemist\ComponentShapePluginBase::getPresentationalValueKeys()
   */
  public function isProvidedValueEmpty(mixed $value): bool;

}
