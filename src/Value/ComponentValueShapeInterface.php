<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;

/**
 * The handle a ComponentValue plugin inherits on its shape.
 *
 * The shape was split into fourteen roles so a caller could accept the
 * smallest one covering what it uses. A ComponentValue plugin could not take
 * that offer: the base's `$shape` property and the accessor's return type were
 * both the full union (`ComponentShapePluginInterface`, ~93 signatures), so
 * every producer held a hundred-method handle no matter how little it asked of
 * it. This names what a producer is *entitled* to ask its shape, no more.
 *
 * **Not one role — a small union of three, one line each:**
 *
 * - {@see ComponentShapeContextInterface} — which prop, and what it is attached
 *   to (component / entity / scope / target entity type). The dominant reach
 *   (18 of the 27 shape-touching plugins) and the home of the two heaviest
 *   methods, getComponent() and getEntity().
 * - {@see ComponentShapeValueInterface} — read and test the value being
 *   threaded through the provider search. Every producer is owed this: the
 *   site-builder "Processing" mode mixed into every producer decides a claim
 *   through
 *   {@see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait::claimsValue()},
 *   which asks `isProvidedValueEmpty()`.
 * - {@see CacheableResponseInterface} — record what the produced value depends
 *   on (addCacheableDependency() / getCacheableMetadata(), 11 plugins). This is
 *   core's interface, not one of the fourteen roles, so it is folded in here
 *   **deliberately**: a role-narrowed handle would otherwise drop cacheability,
 *   and re-widening the handle back to the union to recover it is the one
 *   outcome ticket 09 forbids. Given a home here, the reach stays typed.
 *
 * Both Context and Value extend ComponentShapeIdentityInterface, so naming the
 * prop (getName() / id()) comes free.
 *
 * **A producer needing more declares it — and the mechanism runs in the one
 * legal direction.** The union is a subtype of every role, hence a subtype of
 * this handle, so a base method declaring `: ComponentValueShapeInterface` may
 * be overridden with `: ComponentShapePluginInterface` (a covariant *narrowing*
 * of the return type) — never the reverse. A plugin that reads the schema,
 * walks the tree or builds the prop form overrides
 * {@see ComponentValuePluginInterface::getShape()} to return the union and
 * reaches those roles through `$this->getShape()`. Reaching a non-handle method
 * through the narrowed `$this->shape` property is then a static error a reader
 * and an analyser can see, rather than a runtime fatal on one configuration.
 *
 * Whatever the analyser reads is the contract — verify it. Reflection reports a
 * `self` / `static` return type as the literal string, not the class, so every
 * getShape() override in this module returns an explicit interface name;
 * {@see \Drupal\Tests\neo_alchemist\Unit\Value\ComponentValueShapeHandleTest}
 * pins that.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::getShape()
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentValueShapeInterface extends
  ComponentShapeContextInterface,
  ComponentShapeValueInterface,
  CacheableResponseInterface {

}
