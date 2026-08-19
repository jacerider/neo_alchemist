<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\NestedArray;

/**
 * The empty/default/access options recorded for a shape and its descendants.
 *
 * Every shape in a prop's tree carries three options — whether it renders
 * nothing, whether it sits on its own default value, and whether an editor may
 * change it. They are recorded here rather than on the shapes themselves,
 * because a parent sets them for children that do not exist yet: a value
 * provider runs during the parent's init(), before the child shapes are built.
 *
 * Two layers, and the difference between them is who spoke:
 *
 * - The **saved** layer is what a site builder configured or a provider decided
 *   outright. It is what round-trips through `settings.props.*.options`.
 * - The **fallback** layer is what a provider would like when nothing else has
 *   an opinion — a sourced sub-prop starting on the value its source produced,
 *   say.
 *
 * ::toArray() unions them by TOP-LEVEL key rather than merging, so a single
 * saved option for a shape discards every fallback that shape had. That is a
 * sharp edge, not a subtlety: it is why a heading sub-prop with editing turned
 * off never showed the hidden state a source had asked for. It is preserved
 * deliberately — changing it moves rendered output on live sites — and pinned
 * by NestedOptionMapTest so that changing it stays a decision.
 *
 * ::get() reads the saved layer alone, which is the second asymmetry worth
 * knowing. A caller asking "was this decided?" wants what was decided, not what
 * something would have liked.
 *
 * ## Keys
 *
 * A shape's own options are keyed by its id — the tilde-chained path
 * ComponentShapePluginBase::id() builds, which a shape hands over rather than
 * this class deriving. A CHILD's key is the owner's id joined to the child's
 * name by the same separator, and building *that* is this class's job alone. It
 * used to be done in three shape methods carrying a `$prependCurrentId` flag,
 * plus once more by hand in DefaultValue, and the three disagreed about the
 * flag's default — so a write and a read of the same option could land on
 * different keys. There is no flag now: ::set(), ::setFallback() and ::get()
 * take a child name relative to the shape the view is scoped to, and ::getOwn()
 * / ::replaceOwn() mean the shape itself.
 *
 * ## Views
 *
 * One map instance holds the store; ::forShape() makes cheap views onto it, one
 * per shape that wants to read or write. This is what replaced the seven "if I
 * am the root, store; otherwise delegate to the root" branches that the shape
 * family used to carry: a non-root shape's view points at the root's store, so
 * the delegation is a property of the object graph rather than a branch written
 * out per accessor.
 *
 * Three methods are exceptions to the scoping and address the whole map by
 * absolute id: ::merge(), ::mergeFallbacks() and ::toArray(). They are how a
 * stored subtree enters and leaves the map, and calling them on a view is not
 * an error — the view's scope simply does not apply. ::subtree() is the scoped
 * counterpart, and is what a caller narrowing the map to one shape wants.
 *
 * Holds no shape and no container, so it is assertable directly.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface::getNestedOptionMap()
 * @see \Drupal\neo_alchemist\ComponentShapeOption
 */
final class NestedOptionMap {

  /**
   * The shape renders nothing.
   */
  public const OPTION_EMPTY = 'empty';

  /**
   * The shape sits on its own default value rather than an authored one.
   */
  public const OPTION_DEFAULT = 'default';

  /**
   * An editor may change the shape's value.
   */
  public const OPTION_ACCESS = 'access';

  /**
   * The separator between the segments of a shape id.
   */
  private const SEPARATOR = '~';

  /**
   * Saved options, keyed by shape id and then by option name.
   *
   * Only populated on the store. Values arrive as bools from code and as ints
   * from config, and are read as truthiness rather than by identity.
   *
   * @var array<string, array<string, bool|int>>
   */
  private array $saved = [];

  /**
   * Fallback options, keyed the same way as ::$saved.
   *
   * @var array<string, array<string, bool|int>>
   */
  private array $fallback = [];

  /**
   * Constructs a map, or a view onto one.
   *
   * Callers outside this class construct the store — `new NestedOptionMap()` —
   * and reach shapes through ::forShape().
   *
   * @param string $shapeId
   *   The id of the shape this view speaks for. Empty on the store itself,
   *   which holds the arrays but answers for no particular shape.
   * @param \Drupal\neo_alchemist\NestedOptionMap|null $store
   *   The map holding the arrays, or NULL when this instance is that map.
   */
  public function __construct(
    private readonly string $shapeId = '',
    private readonly ?self $store = NULL,
  ) {}

  /**
   * Returns a view onto this store, scoped to one shape.
   *
   * Views are made per call rather than cached, because a shape's id changes as
   * parents are added to it and a stale scope would write to the wrong key.
   *
   * @param string $shapeId
   *   The shape id to scope to.
   *
   * @return static
   *   The view.
   */
  public function forShape(string $shapeId): static {
    return new static($shapeId, $this->store());
  }

  /**
   * Reads a saved option recorded for one of this shape's children.
   *
   * The fallback layer is deliberately invisible here.
   *
   * @param string $child
   *   The child's name, relative to this shape.
   * @param string $option
   *   One of the OPTION_* constants on this class.
   *
   * @return bool
   *   The option, FALSE when nothing was saved for it.
   */
  public function get(string $child, string $option): bool {
    return !empty($this->store()->saved[$this->childKey($child)][$option]);
  }

  /**
   * Records a saved option for one of this shape's children.
   *
   * @param string $child
   *   The child's name, relative to this shape.
   * @param string $option
   *   One of the OPTION_* constants on this class.
   * @param bool $value
   *   The option. There is no meaningful default for every option — access in
   *   particular is normally recorded to withdraw it — so callers state it.
   *
   * @return $this
   */
  public function set(string $child, string $option, bool $value = TRUE): static {
    $this->store()->saved[$this->childKey($child)][$option] = $value;
    return $this;
  }

  /**
   * Records a fallback option for one of this shape's children.
   *
   * @param string $child
   *   The child's name, relative to this shape.
   * @param string $option
   *   One of the OPTION_* constants on this class.
   * @param bool $value
   *   The option.
   *
   * @return $this
   */
  public function setFallback(string $child, string $option, bool $value = TRUE): static {
    $this->store()->fallback[$this->childKey($child)][$option] = $value;
    return $this;
  }

  /**
   * Returns the options recorded for this shape itself.
   *
   * @return array
   *   Option name => value, saved unioned over fallback.
   */
  public function getOwn(): array {
    return $this->toArray()[$this->shapeId] ?? [];
  }

  /**
   * Replaces the options recorded for this shape itself.
   *
   * Replaces rather than merges: the caller is a submitted form's `_options`,
   * which carries every checkbox the shape offered, so an option the site
   * builder cleared arrives as 0 rather than going missing.
   *
   * @param array $options
   *   Option name => value.
   *
   * @return $this
   */
  public function replaceOwn(array $options): static {
    $this->store()->saved[$this->shapeId] = $options;
    return $this;
  }

  /**
   * Adds saved options without overwriting any already recorded.
   *
   * @param array $options
   *   Shape id => option name => value. Keys are absolute ids, because this is
   *   how a whole stored subtree re-enters the map.
   *
   * @return $this
   */
  public function merge(array $options): static {
    $store = $this->store();
    $store->saved = NestedArray::mergeDeep($options, $store->saved);
    return $this;
  }

  /**
   * Adds fallback options without overwriting any already recorded.
   *
   * @param array $options
   *   Shape id => option name => value, keyed absolutely as in ::merge().
   *
   * @return $this
   */
  public function mergeFallbacks(array $options): static {
    $store = $this->store();
    $store->fallback = NestedArray::mergeDeep($options, $store->fallback);
    return $this;
  }

  /**
   * Returns every option in the map, saved unioned over fallback.
   *
   * The union is by top-level key — see the class docblock.
   *
   * @return array
   *   Shape id => option name => value.
   */
  public function toArray(): array {
    $store = $this->store();
    return $store->saved + $store->fallback;
  }

  /**
   * Returns this shape's own options and those of its descendants.
   *
   * A provider storing its configuration narrows the map to its own shape this
   * way, so that it carries its options and not the whole component's.
   *
   * @return array
   *   Shape id => option name => value.
   */
  public function subtree(): array {
    $prefix = $this->shapeId . self::SEPARATOR;
    return array_filter(
      $this->toArray(),
      fn (string $key): bool => $key === $this->shapeId || str_starts_with($key, $prefix),
      ARRAY_FILTER_USE_KEY,
    );
  }

  /**
   * Builds the key a child of this shape is recorded under.
   *
   * Public because one caller addresses the map's keys without holding the map:
   * ArrayShape rewrites its children's options into their new deltas while
   * massaging raw submitted values, before any of those children exist as
   * shapes. It asks here rather than joining the segments itself, so there is
   * still only one place that knows what a child key looks like.
   *
   * @param string $child
   *   The child's name, relative to this shape.
   * @param int|string|null $delta
   *   The delta the child sits at, when it is one of an iterable's items.
   *
   * @return string
   *   The absolute key.
   */
  public function childKey(string $child, int|string|null $delta = NULL): string {
    assert($this->shapeId !== '', 'A nested option belongs to a shape; reach the map through ::forShape().');
    $key = $this->shapeId . self::SEPARATOR . $child;
    return $delta === NULL ? $key : $key . self::SEPARATOR . $delta;
  }

  /**
   * Returns the map holding the arrays.
   *
   * @return static
   *   The store, which is this instance when it is not a view.
   */
  private function store(): static {
    return $this->store ?? $this;
  }

}
