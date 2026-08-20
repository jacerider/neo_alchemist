<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * What a producer decided about a shape's individual children.
 *
 * A children-match provider walks the mapping a site builder configured and
 * records, per child shape id, whether that child should be hidden, pinned to
 * its default, or locked, plus any per-child value plugins it wants attached.
 * ChildOptionPolicy is what turns those records into locked options when the
 * children are built.
 *
 * One of these lives on the root shape and is shared by every shape beneath it,
 * because the ids a producer records are chained from the root
 * (`root~child~grandchild`) and only the root can key them all.
 *
 * This replaced nine methods on the shape family, seven of which were the same
 * "if I am the root, store; otherwise delegate to the root" branch written out
 * again. That branch is now written once, in
 * ChildShapeStateTrait::getChildShapeState().
 *
 * ## Views, and the deadline they carry
 *
 * The store/view/seal machinery is ShapeScopedStoreTrait's, shared with
 * NestedOptionMap because both stores hang off the root shape and both close
 * to writes when a shape initializes. Every method here takes an ABSOLUTE
 * child id, so unlike the option map this class does not key by its scope —
 * the scope exists only to carry the deadline, and so that a writer says which
 * shape it is speaking for.
 *
 * ::setFlag(), ::enablePlugin() and ::disablePlugin() honour the seal; the two
 * readers do not, because reading is exactly what ChildOptionPolicy does once
 * the children start being built.
 *
 * Holds no shape and no container, so it is assertable directly.
 *
 * @see \Drupal\neo_alchemist\ShapeScopedStoreTrait::seal()
 * @see \Drupal\neo_alchemist\ChildOptionPolicy
 * @see \Drupal\neo_alchemist\NestedOptionMap
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildShapeStateTrait
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
 */
final class ChildShapeState {

  use ShapeScopedStoreTrait;

  /**
   * The child renders nothing.
   */
  const HIDDEN = 'hidden';

  /**
   * The child falls back to its own default rather than a provided value.
   */
  const USE_DEFAULT = 'default';

  /**
   * The child's configuration is not the content editor's to change.
   *
   * Read by ChildOptionPolicy; no producer sets it today. It is named here
   * rather than dropped because the reader is live and wiring a writer is a
   * call to ::setFlag(), not a new method.
   */
  const LOCKED = 'locked';

  /**
   * Producer decisions, keyed by child shape id and then by flag name.
   *
   * Three-valued per flag: TRUE, FALSE, or absent. Absent means no producer
   * spoke for this child, which is not the same as a producer saying FALSE —
   * an explicit FALSE outranks the constraints a parent would otherwise
   * inherit down.
   *
   * @var array<string, array<string, bool>>
   */
  private array $flags = [];

  /**
   * Per-child value plugin configuration, keyed by child shape id.
   *
   * @var array<string, array<string, array{status: bool, settings: array}>>
   */
  private array $plugins = [];

  /**
   * Records a producer's decision about one child.
   *
   * Refused once the shape has initialized. This is the deadline the
   * nine-method trait carried before this class existed: hideChildShape(),
   * defaultChildShape() and lockChildShape() each asserted
   * `!$this->isInitialized()` before writing, and those assertions were
   * dropped when the writers stopped being shape methods. The seal restores
   * it, and covers every writer rather than the three that happened to carry
   * a guard.
   *
   * @param string $shapeId
   *   The child shape id. Not the child's name — the id chained from the root.
   * @param string $flag
   *   One of the flag constants on this class.
   * @param bool $value
   *   The decision.
   *
   * @return $this
   */
  public function setFlag(string $shapeId, string $flag, bool $value = TRUE): self {
    $this->assertNotSealed('Child shape decisions');
    $this->store()->flags[$shapeId][$flag] = $value;
    return $this;
  }

  /**
   * Reads a producer's decision about one child.
   *
   * @param string $shapeId
   *   The child shape id.
   * @param string $flag
   *   One of the flag constants on this class.
   *
   * @return bool|null
   *   The decision, or NULL if no producer spoke for this child.
   */
  public function getFlag(string $shapeId, string $flag): ?bool {
    return $this->store()->flags[$shapeId][$flag] ?? NULL;
  }

  /**
   * Attaches a value plugin to one child.
   *
   * @param string $shapeId
   *   The child shape id.
   * @param string $pluginId
   *   The value plugin id.
   * @param array $settings
   *   The plugin settings.
   *
   * @return $this
   */
  public function enablePlugin(string $shapeId, string $pluginId, array $settings = []): self {
    $this->assertNotSealed('Child shape plugins');
    $this->store()->plugins[$shapeId][$pluginId] = [
      'status' => TRUE,
      'settings' => $settings,
    ];
    return $this;
  }

  /**
   * Marks a value plugin as not to be attached to one child.
   *
   * Recorded rather than removed: the shape building the child reads the entry
   * to stop the plugin initializing by default, which an absent entry would
   * not do.
   *
   * @param string $shapeId
   *   The child shape id.
   * @param string $pluginId
   *   The value plugin id.
   *
   * @return $this
   */
  public function disablePlugin(string $shapeId, string $pluginId): self {
    $this->assertNotSealed('Child shape plugins');
    $this->store()->plugins[$shapeId][$pluginId]['status'] = FALSE;
    return $this;
  }

  /**
   * Gets the value plugin configuration recorded for one child.
   *
   * @param string $shapeId
   *   The child shape id.
   *
   * @return array
   *   The plugin configuration, keyed by plugin id.
   */
  public function getPlugins(string $shapeId): array {
    return $this->store()->plugins[$shapeId] ?? [];
  }

}
