<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ChildOptionPolicy;
use Drupal\neo_alchemist\ChildShapeState;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;

/**
 * Gives a shape the two collaborators it needs to build child shapes.
 *
 * The state records what a producer decided about individual children; the
 * policy applies those decisions when the children are built. Any shape (not
 * just ChildrenShapeBase subclasses) can opt in to field-to-child matching by
 * using this trait and running the policy over each child.
 *
 * The state lives on the ROOT shape and every shape beneath it delegates,
 * because the ids a producer records are chained from the root and only the
 * root can key them all. That delegation is written once here — it used to be
 * seven copies of the same branch across nine methods.
 */
trait ChildShapeStateTrait {

  /**
   * The producer decisions, on the root shape only.
   *
   * Read it through ::getChildShapeState(), never directly: on any shape that
   * is not the root this stays NULL and the real state is the root's.
   *
   * @var \Drupal\neo_alchemist\ChildShapeState|null
   */
  protected ?ChildShapeState $childShapeState = NULL;

  /**
   * The policy that applies this shape's constraints to its children.
   *
   * @var \Drupal\neo_alchemist\ChildOptionPolicy|null
   */
  protected ?ChildOptionPolicy $childOptionPolicy = NULL;

  /**
   * {@inheritdoc}
   */
  public function getChildShapeState(): ChildShapeState {
    if (!$this->isRoot()) {
      return $this->getChildRootShape()->getChildShapeState();
    }
    return $this->childShapeState ??= new ChildShapeState();
  }

  /**
   * {@inheritdoc}
   */
  public function getChildShapePlugins(string $shapeId): array {
    $root = $this->getChildRootShape();
    $plugins = [];
    // Merge in plugins stored on the root shape. Only iterable shapes need
    // this: a non-iterable child carries no delta, so its configuration is
    // already found on the base shape.
    if ($root->isIterable()) {
      foreach ($root->getPlugins()[$shapeId] ?? [] as $pluginId => $plugin) {
        $plugins[$pluginId] = [
          'status' => TRUE,
          'settings' => $plugin['settings'],
        ];
      }
    }
    return $plugins + $root->getChildShapeState()->getPlugins($shapeId);
  }

  /**
   * Gets the root shape that owns the child-shape state.
   *
   * @return \Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface
   *   The root shape.
   */
  protected function getChildRootShape(): ComponentShapeChildrenMatchPluginInterface {
    if ($this->isRoot()) {
      return $this;
    }
    $rootShape = $this->getRootShape();
    if ($rootShape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $rootShape;
    }
    throw new \RuntimeException('Root shape does not implement ComponentShapeChildrenMatchPluginInterface.');
  }

  /**
   * Gets the policy that applies this shape's constraints to its children.
   *
   * Every base that builds child shapes must run this over each child before
   * initializing it. Stateless, so one instance serves every child.
   *
   * @return \Drupal\neo_alchemist\ChildOptionPolicy
   *   The child option policy.
   */
  protected function childOptionPolicy(): ChildOptionPolicy {
    return $this->childOptionPolicy ??= new ChildOptionPolicy();
  }

}
