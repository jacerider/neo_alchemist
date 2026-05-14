<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;

/**
 * Shared state tracking for shapes that expose child shapes.
 *
 * Holds the hide/default/lock flags and per-child plugin settings consumed by
 * ComponentValueChildrenMatchTrait, so any shape (not just ChildrenShapeBase
 * subclasses) can opt in to field-to-child matching.
 */
trait ChildShapeStateTrait {

  /**
   * A list of child shapes to hide.
   *
   * @var bool[]
   */
  protected $hideChildShapes = [];

  /**
   * A list of child shapes to set as default.
   *
   * @var bool[]
   */
  protected $defaultChildShapes = [];

  /**
   * A list of child shapes to set as locked.
   *
   * @var bool[]
   */
  protected $lockChildShapes = [];

  /**
   * A list of child shape plugins.
   *
   * @var array[]
   */
  protected $childShapePlugins = [];

  /**
   * Hide a child shape.
   */
  public function hideChildShape(string $shapeId, $hide = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before hiding child shapes.');
    if ($this->isRoot()) {
      $this->hideChildShapes[$shapeId] = $hide;
    }
    else {
      return $this->getChildRootShape()->hideChildShape($shapeId, $hide);
    }
    return $this;
  }

  /**
   * Check if a child shape is hidden.
   */
  public function isHiddenChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->hideChildShapes[$shapeId] ?? NULL;
    }
    return $this->getChildRootShape()->isHiddenChildShape($shapeId);
  }

  /**
   * Default a child shape.
   */
  public function defaultChildShape(string $shapeId, $default = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before defaulting child shapes.');
    if ($this->isRoot()) {
      $this->defaultChildShapes[$shapeId] = $default;
    }
    else {
      return $this->getChildRootShape()->defaultChildShape($shapeId, $default);
    }
    return $this;
  }

  /**
   * Check if a child shape is default.
   */
  public function isDefaultChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->defaultChildShapes[$shapeId] ?? NULL;
    }
    return $this->getChildRootShape()->isDefaultChildShape($shapeId);
  }

  /**
   * Lock a child shape.
   */
  public function lockChildShape(string $shapeId, $lock = TRUE): self {
    assert(!$this->isInitialized(), 'Shape cannot be initialized before locking child shapes.');
    if ($this->isRoot()) {
      $this->lockChildShapes[$shapeId] = $lock;
    }
    else {
      return $this->getChildRootShape()->lockChildShape($shapeId, $lock);
    }
    return $this;
  }

  /**
   * Check if a child shape is locked.
   */
  public function isLockedChildShape(string $shapeId): ?bool {
    if ($this->isRoot()) {
      return $this->lockChildShapes[$shapeId] ?? NULL;
    }
    return $this->getChildRootShape()->isLockedChildShape($shapeId);
  }

  /**
   * Enable a child shape plugin.
   */
  public function enableChildShapePlugin(string $shapeId, string $pluginId, array $settings = []): self {
    if ($this->isRoot()) {
      $this->childShapePlugins[$shapeId][$pluginId] = [
        'status' => TRUE,
        'settings' => $settings,
      ];
    }
    else {
      return $this->getChildRootShape()->enableChildShapePlugin($shapeId, $pluginId, $settings);
    }
    return $this;
  }

  /**
   * Disable a child shape plugin.
   */
  public function disableChildShapePlugin(string $shapeId, string $pluginId): self {
    if ($this->isRoot()) {
      $this->childShapePlugins[$shapeId][$pluginId]['status'] = FALSE;
    }
    else {
      return $this->getChildRootShape()->disableChildShapePlugin($shapeId, $pluginId);
    }
    return $this;
  }

  /**
   * Get child shape plugins.
   */
  public function getChildShapePlugins(string $shapeId): array {
    if ($this->isRoot()) {
      $plugins = [];
      if ($this->isIterable()) {
        // Merge in plugins stored on root shape. We only need to do this for
        // iterable shapes as non-iterable shapes will automatically be pulled
        // from the base shape because they do not have a delta.
        foreach ($this->getPlugins()[$shapeId] ?? [] as $plugin_id => $plugin) {
          $plugins[$plugin_id] = [
            'status' => TRUE,
            'settings' => $plugin['settings'],
          ];
        }
      }
      return $plugins + ($this->childShapePlugins[$shapeId] ?? []);
    }
    return $this->getChildRootShape()->getChildShapePlugins($shapeId);
  }

  /**
   * Gets the root shape that exposes child-shape state.
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

}
