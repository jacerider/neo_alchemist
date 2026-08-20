<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

/**
 * A shape that has not initialized yet, and the setters that must run first.
 *
 * This is the one role ComponentShapePluginInterface does NOT extend, and that
 * is its whole purpose. Every other role narrows what a caller has to *learn*
 * about a shape; this one narrows what a caller may *do* to it. Hold a shape as
 * the union — as every existing type hint does — and none of these are
 * callable, so setting a value or a parent after the fact is a static error
 * rather than a silent no-op.
 *
 * The lifecycle it makes visible was previously written nowhere in the type: a
 * set of setters had to run before ::init(), the value, form and field-item
 * getters only meant anything after, and the constraint was carried by
 * `assert(!$this->isInitialized(), …)` at a minority of the sites that needed
 * it. Assertions compile out in production, so a mis-ordered call shipped a
 * wrong value rather than an error.
 *
 * ## Getting one
 *
 * From ComponentShapePluginManager — ::getInstance(),
 * ::getInstancesFromSchema() and ::getChildInstancesFromSchema() all hand back
 * shapes under construction. Nothing converts an initialised shape back into
 * one, by design.
 *
 * ## Handing one on
 *
 * ::init() returns the union. That is the seam: everything the setup code
 * passes on afterwards is an initialised shape, and the setters are gone from
 * its type. The setters themselves return this interface so a chain of them
 * does not widen halfway through.
 *
 * ## What this deliberately does not carry
 *
 * Three field-item setters — ::setFieldType(), ::setFieldStorageSettings() and
 * ::setFieldInstanceSettings() — belong to the same lifecycle and stay on
 * ComponentShapeFieldItemInterface, keeping their assertions. They are called
 * from ComponentValuePluginInterface::onShapeInit(), which runs *during*
 * init() through a shape handle the value plugin holds as the union; a type
 * cannot withdraw a method from a handle it does not own. Narrowing that
 * handle belongs to the componentvalue-pipeline spec.
 *
 * The child-shape state writers are not here either, for the same reason one
 * level down: they are `getChildShapeState()->setFlag()`, a collaborator's
 * method reached through an accessor ChildOptionPolicy goes on reading after
 * init. ChildShapeState::seal() carries that deadline instead, as
 * NestedOptionMap::seal() already did for child options.
 *
 * A full builder/initialised-shape split is the endpoint and is deliberately
 * staged: it would touch every shape subclass at once for a payoff this
 * mostly delivers.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 * @see \Drupal\neo_alchemist\Shape\ComponentShapeLifecycleInterface
 * @see \Drupal\neo_alchemist\Shape\ChildShapeState::seal()
 */
interface ComponentShapeSetupInterface extends ComponentShapePluginInterface {

  /**
   * Adds a parent shape to the current component shape.
   *
   * Before init() because a shape's id is chained from its parents, and the
   * options a producer records are keyed by that id. A parent added afterwards
   * renames the shape out from under everything already written for it.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $parent
   *   The parent shape to be added.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function addParentShape(ComponentShapePluginInterface $parent): ComponentShapeSetupInterface;

  /**
   * Sets the nested delta of the shape.
   *
   * Before init() for the same reason as ::addParentShape(): the delta is the
   * last segment of the shape id.
   *
   * @param int $delta
   *   The nested delta.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function setDelta(int $delta): ComponentShapeSetupInterface;

  /**
   * Sets the value a parent shape is pushing down into this one.
   *
   * Before init(), which reads it to seed the field item. A parent value
   * arriving later is simply not consulted.
   *
   * @param mixed $value
   *   The value to set as the parent override.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function setParentValue(mixed $value): ComponentShapeSetupInterface;

  /**
   * Sets the override value.
   *
   * Before init(), which reads it to overlay the field item once the provider
   * chain has run.
   *
   * @param mixed $value
   *   The value to set as the override.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function setOverrideValue(mixed $value): ComponentShapeSetupInterface;

  /**
   * Allows or disallows initialization of specific plugins.
   *
   * If a shape has default_plugins, this stops one of them being initialized.
   * Before init(), which is what runs them — a producer configuring a child's
   * plugins itself turns the automatic ones off this way first.
   *
   * @param string $pluginId
   *   The ID of the plugin.
   * @param bool $allow
   *   Whether to allow initialization. Defaults to TRUE.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function allowInitPlugins(string $pluginId, bool $allow = TRUE): ComponentShapeSetupInterface;

  /**
   * Adds a value plugin with the given ID and settings.
   *
   * Before init(), which collects the value collection this adds to and runs
   * it. A plugin added afterwards never provides anything.
   *
   * @param string $pluginId
   *   The ID of the plugin to set.
   * @param array $settings
   *   (optional) An associative array of settings for the plugin. Defaults to
   *   an empty array.
   * @param bool $status
   *   (optional) Whether the plugin is enabled. Defaults to TRUE.
   *
   * @return $this
   *   The shape, still under construction.
   */
  public function addPlugin(string $pluginId, array $settings = [], bool $status = TRUE): ComponentShapeSetupInterface;

  /**
   * Initializes the shape and calculates the value of the field item.
   *
   * The pipeline that turns a configured shape into one holding a value: the
   * schema default, then the provider chain, then the parent and override
   * values, then the field item. It is the line the rest of the shape is
   * ordered around, and it is one-shot.
   *
   * Returns the union rather than this interface on purpose. That is the
   * handoff — whatever init() is called on, what comes back out is an
   * initialised shape with the setup setters gone from its type.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The shape, initialised.
   */
  public function init(): ComponentShapePluginInterface;

}
