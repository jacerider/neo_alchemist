<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

/**
 * Marks a value plugin that sources its own value — the producer role.
 *
 * A plugin plays exactly one role in producing a prop's value, and one of those
 * roles is "producer": it looks a value up somewhere (an entity field, a menu,
 * a view, the current route) and offers it into the provide phase. The other
 * roles do not source anything — the terminal `fallback` (`default`) only fills
 * an empty value, `modifiers` transform an existing one, and `settings` never
 * touch the value at all.
 *
 * Declaring THIS interface — not picking a `group` string — is how a plugin
 * says it is a producer. That distinction is the whole point: a plugin's group
 * is also its sort weight and its form tab, so choosing a group to place a
 * plugin nicely in the UI must not double as a declaration that it sources a
 * value. Before the role was a type it was the literal string `'providers'`,
 * and a plugin filed under another group for the form's sake silently dropped
 * out of the question the role answers, with no error anywhere: whether a child
 * shape sources its own value, so that a child which does is not clobbered by a
 * value pushed down from its parent. Miss it and the child refuses nothing and
 * renders the parent's schema example over authored content.
 *
 * A producer still declares its `group` for weight and form placement; the
 * group is simply no longer asked whether the plugin is a producer. Every
 * producer shipped by this module carries this interface, so the compatibility
 * shim on ComponentValuePluginBase::isValueProducer() only ever answers for
 * producers defined in other packages that do not implement it — on this site,
 * neo_site_settings. ComponentValueProducerScopeTest pins the type against the
 * group for every producer the base install discovers.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::isValueProducer()
 * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase::isValueProducer()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::childHasOwnValueProvider()
 */
interface ComponentValueProducerInterface extends ComponentValuePluginInterface {
}
