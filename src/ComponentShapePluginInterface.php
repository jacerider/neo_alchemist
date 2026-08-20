<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessibleInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Plugin\ObjectWithPluginCollectionInterface;

/**
 * Interface for neo_component_shape plugins.
 *
 * This is the union of the shape's roles, and declares nothing of its own. It
 * exists so every type hint that predates the split keeps working; it is not
 * the type new code should reach for.
 *
 * **Accept the smallest role that covers what you use.** A ComponentValue
 * plugin that resolves a value wants ComponentShapeValueInterface — eight
 * signatures, twelve with the identity it extends — not this, which is
 * ninety-three. Narrowing is also what makes a test double honest: mock a
 * role and a misspelled method name fails the test, where on a double this
 * wide it returns NULL and the test passes.
 *
 * Narrowing bounds what you may call on the shape you were handed; it does not
 * bound the tree. ComponentShapeTreeInterface hands back whole shapes, because
 * arriving at a parent or the root is normally the prelude to asking it
 * something a tree role could not answer. Walking the tree therefore widens
 * again, deliberately.
 *
 * The roles, and the caller need each answers:
 *
 * - ComponentShapeIdentityInterface — name this shape. Extended by every other
 *   role, so any of them can say which prop it was working on.
 * - ComponentShapeSchemaInterface — read the prop schema.
 * - ComponentShapeValueInterface — resolve a value.
 * - ComponentShapeRenderInterface — render a value.
 * - ComponentShapeFormInterface — build and process the prop form.
 * - ComponentShapeFieldMatchInterface — match an entity field to the prop.
 * - ComponentShapeFieldItemInterface — treat the prop as a field item.
 * - ComponentShapeTreeInterface — walk the shape tree.
 * - ComponentShapeExpansionInterface — ask what is expanded.
 * - ComponentShapeProvidersInterface — read the value providers.
 * - ComponentShapeContextInterface — ask what the shape is attached to.
 * - ComponentShapeStateInterface — ask active/required/editable/locked.
 * - ComponentShapeOptionsInterface — read the empty/default/access options.
 * - ComponentShapeLifecycleInterface — say whether it initialised, and receive
 *   the component's events.
 *
 * **One role is deliberately missing.** ComponentShapeSetupInterface holds the
 * setters that must run before init(), and this union does not extend it — so
 * holding a shape as this type means holding one that has been initialised,
 * and setting a parent, a delta, a value or a plugin on it will not compile.
 * That constraint used to live in `assert(!$this->isInitialized(), …)` calls,
 * which compile out in production. Setup extends this union rather than the
 * other way round: a shape under construction is a shape with more available,
 * and ::init() hands back this type.
 *
 * Boundaries were drawn from the measured call sites rather than by grouping
 * the implementation: of the module's 218 shape consumers, the median reaches
 * into one role and 82% reach into two or fewer. The two that need nearly all
 * of them — the component entity and the default value plugin — are the
 * orchestrators this union is for.
 *
 * Shapes also implement capability interfaces saying what kind of shape they
 * are, such as the children and media ones below. Those are a separate axis: a
 * role is a view a caller takes of any shape; a capability is something only
 * some shapes have.
 *
 * @see \Drupal\neo_alchemist\ComponentShapeSetupInterface
 * @see \Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface
 * @see \Drupal\neo_alchemist\ComponentShapeMediaPluginInterface
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase
 */
interface ComponentShapePluginInterface extends
  PluginInspectionInterface,
  DerivativeInspectionInterface,
  ObjectWithPluginCollectionInterface,
  AccessibleInterface,
  CacheableResponseInterface,
  ComponentShapeIdentityInterface,
  ComponentShapeSchemaInterface,
  ComponentShapeValueInterface,
  ComponentShapeRenderInterface,
  ComponentShapeFormInterface,
  ComponentShapeFieldMatchInterface,
  ComponentShapeFieldItemInterface,
  ComponentShapeTreeInterface,
  ComponentShapeExpansionInterface,
  ComponentShapeProvidersInterface,
  ComponentShapeContextInterface,
  ComponentShapeStateInterface,
  ComponentShapeOptionsInterface,
  ComponentShapeLifecycleInterface {

}
