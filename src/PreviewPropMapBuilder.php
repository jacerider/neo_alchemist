<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;

/**
 * Builds the prop map delivered to a single-component editor preview.
 *
 * The map is attached as drupalSettings.neoAlchemist.propMap and lets the
 * preview iframe's script (component-child.ts) resolve which prop produced a
 * piece of DOM. Attribute-carrying prop values are stamped with data-neo-prop
 * server-side and need no map; everything else (strings, headings, images,
 * links) is matched heuristically in the iframe against the hints collected
 * here — nothing is ever injected into the rendered markup itself.
 *
 * Hint paths are joined with `~` to shape ids (the nested path with each
 * owning row's delta at its own depth, matching
 * ComponentShapePluginBase::id()), so a hint that has no shape of its own
 * attaches to its closest owning shape — a link's `title` text lands on the
 * link prop, an image's `alt` on the image prop.
 */
final class PreviewPropMapBuilder {

  /**
   * Longest string that still makes a useful exact-match text hint.
   */
  private const MAX_TEXT_HINT_LENGTH = 300;

  /**
   * Builds the prop map for an editor preview.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The previewed component.
   * @param array $renderable
   *   The build returned by $component->toRenderable(), whose #props already
   *   hold the resolved render values — no second value resolution happens
   *   here.
   *
   * @return array
   *   The map: component uuid plus per-shape-id metadata and hints.
   */
  public static function build(ComponentInterface $component, array $renderable): array {
    $shapes = [];
    foreach ($component->getPropShapesAll(NULL, TRUE) as $id => $shape) {
      if (!$shape->access('update')) {
        continue;
      }
      $shapes[$id] = [
        'root' => explode('~', (string) $id)[0],
        'title' => $shape->getNestedTitle(),
        'ref' => $shape->getRef(),
        'type' => $shape->getType(),
        // A style shape owns no element of its own — buildRenderValue() skips
        // the data-neo-prop stamp for exactly this interface, because stamping
        // one turns the whole component into a hover target for a presentation
        // prop. It still has a scope, though: the component it restyles. The
        // preview reads this to outline that component when such a prop is
        // focused, which costs no new hover target because it is resolved at
        // focus time rather than written into the markup.
        'style' => $shape instanceof ComponentShapeStylePluginInterface,
        'hints' => ['text' => [], 'src' => [], 'href' => []],
      ];
    }

    $props = $renderable['#props'] ?? [];
    unset($props['attributes'], $props['neoId'], $props['neoUuid'], $props['neoIsPreview']);
    $prefix = $component->isAggregate() ? ['_aggregate'] : [];
    foreach ($props as $name => $value) {
      static::walk($shapes, $value, array_merge($prefix, [(string) $name]), []);
    }

    foreach ($shapes as &$info) {
      $info['hints'] = array_filter($info['hints']);
    }
    return [
      'component' => $component->uuid(),
      'props' => $shapes,
    ];
  }

  /**
   * Walks a resolved prop value collecting hints.
   *
   * @param array $shapes
   *   The shape map being filled, keyed by shape id.
   * @param mixed $value
   *   The value at this point of the walk.
   * @param string[] $namePath
   *   The non-numeric key path from the prop root.
   * @param array[] $deltas
   *   Every enclosing row index, outermost first, each as
   *   `['depth' => int, 'delta' => int]`. `depth` is where that index sits in a
   *   shape id — the number of path segments before it — because a row's delta
   *   follows the array child's own segment: the shape holding it is
   *   `items~title~0` and its own children are `items~title~0~title`, a depth
   *   of 2 for a path rooted at `items`.
   *
   *   A list rather than one index because arrays nest. `slides` holds a
   *   `links` array which holds the links themselves, and a single slot made
   *   the inner row index overwrite the outer one — so the second link of the
   *   first slide was hinted as `slides~links~1`, which is the *second slide's*
   *   links prop. Clicking that button in the preview opened a field belonging
   *   to another slide, which is the one answer worse than none.
   */
  private static function walk(array &$shapes, mixed $value, array $namePath, array $deltas): void {
    if ($value instanceof Attribute) {
      // Presentational; already stamped server-side.
      return;
    }
    if (is_string($value) || $value instanceof MarkupInterface) {
      static::addHint($shapes, $namePath, $deltas, 'text', trim(strip_tags((string) $value)));
      return;
    }
    if (!is_array($value)) {
      // Numbers, booleans and helper objects make no useful DOM hints.
      return;
    }
    foreach (array_keys($value) as $key) {
      if (is_string($key) && str_starts_with($key, '#')) {
        // A render array (region children, embedded builds) is not an
        // authored value of this component's shapes.
        return;
      }
    }

    // Keys that identify the composite value itself rather than a child.
    if (isset($value['src']) && is_string($value['src'])) {
      $basename = basename(parse_url($value['src'], PHP_URL_PATH) ?: '');
      static::addHint($shapes, $namePath, $deltas, 'src', $basename);
    }
    foreach (['uri', 'url'] as $key) {
      if (isset($value[$key]) && is_string($value[$key])) {
        static::addHint($shapes, $namePath, $deltas, 'href', static::normalizeHref($value[$key]));
      }
    }

    foreach ($value as $key => $item) {
      if (is_int($key)) {
        // Descending into a row: the delta belongs one segment further in
        // than the path reached here, since the shape that holds it is the
        // array's child rather than the array itself. Pushed rather than
        // replaced, so an array inside a row keeps the row it is inside.
        static::walk($shapes, $item, $namePath, array_merge($deltas, [
          ['depth' => count($namePath) + 1, 'delta' => $key],
        ]));
      }
      else {
        static::walk($shapes, $item, array_merge($namePath, [(string) $key]), $deltas);
      }
    }
  }

  /**
   * Builds a shape id by weaving row indexes into a name path at their depths.
   *
   * Each index inserted shifts the ones after it along, hence the running
   * offset. An index deeper than the path has run out of segments to sit
   * between and is appended instead — which is exactly what a row's own shape
   * id looks like (`items~title~0`).
   */
  private static function weave(array $path, array $deltas): string {
    $segments = $path;
    $inserted = 0;
    foreach ($deltas as $row) {
      $at = min($row['depth'] + $inserted, count($segments));
      array_splice($segments, $at, 0, [(string) $row['delta']]);
      $inserted++;
    }
    return implode('~', $segments);
  }

  /**
   * The one child shape that could own a row of the array at $prefix.
   *
   * An array's rows are not shapes; its children are, one per row —
   * `slides~links~0~link~1` is the link in row 1 of the links array on slide 0.
   * The rendered value carries no segment naming that child, because the row
   * value *is* the link, so the name path alone can never spell the id out.
   *
   * Asking the shape map instead: of the ids that sit one segment below this
   * array and end at this row, exactly one should be able to own DOM. Style
   * shapes are skipped because they are never stamped and own no element, and
   * anything still ambiguous after that returns NULL — the caller then falls
   * back to the array itself, which is coarser but still true.
   */
  private static function findRowChild(array $shapes, string $prefix, int $delta): ?string {
    $suffix = '~' . $delta;
    $found = NULL;
    foreach ($shapes as $id => $info) {
      $id = (string) $id;
      if (!str_starts_with($id, $prefix . '~') || !str_ends_with($id, $suffix)) {
        continue;
      }
      $middle = substr($id, strlen($prefix) + 1, -strlen($suffix));
      if ($middle === '' || str_contains($middle, '~') || !empty($info['style'])) {
        continue;
      }
      if ($found !== NULL) {
        return NULL;
      }
      $found = $id;
    }
    return $found;
  }

  /**
   * Attaches a hint to the closest shape owning the walked path.
   *
   * Tries the id with every row index woven in at its own depth first, then
   * the array's own child for the innermost row, then the same path with the
   * innermost indexes dropped one at a time, then strips trailing path
   * segments — so hints for value keys that are not shapes of their own climb
   * to their owning shape.
   *
   * Going from most specific to least is what keeps it honest: each step up
   * names something larger but still true, and it stops at the first id that
   * exists rather than guessing past it.
   */
  private static function addHint(array &$shapes, array $namePath, array $deltas, string $type, ?string $hint): void {
    if ($hint === NULL || $hint === '' || mb_strlen($hint) > self::MAX_TEXT_HINT_LENGTH) {
      return;
    }
    $path = $namePath;
    while ($path) {
      $candidates = [static::weave($path, $deltas)];
      if ($deltas) {
        $innermost = end($deltas);
        $child = static::findRowChild(
          $shapes,
          static::weave($path, array_slice($deltas, 0, -1)),
          $innermost['delta'],
        );
        if ($child !== NULL) {
          $candidates[] = $child;
        }
      }
      // Drop the innermost index, then the next, down to none: a value inside
      // a row that has no shape of its own belongs to the row's array, and
      // that array may itself be inside a row.
      for ($i = count($deltas) - 1; $i >= 0; $i--) {
        $candidates[] = static::weave($path, array_slice($deltas, 0, $i));
      }
      foreach ($candidates as $candidate) {
        if (isset($shapes[$candidate])) {
          if (!in_array($hint, $shapes[$candidate]['hints'][$type], TRUE)) {
            $shapes[$candidate]['hints'][$type][] = $hint;
          }
          return;
        }
      }
      array_pop($path);
    }
  }

  /**
   * Normalizes a stored uri into something matchable against DOM hrefs.
   *
   * @return string|null
   *   A path or absolute URL, or NULL when the uri needs routing to resolve
   *   (entity:, route:, mailto: …) and is not worth matching client-side.
   */
  private static function normalizeHref(string $uri): ?string {
    foreach (['internal:', 'base:'] as $scheme) {
      if (str_starts_with($uri, $scheme)) {
        $uri = substr($uri, strlen($scheme));
      }
    }
    if ($uri === '' || !(str_starts_with($uri, '/') || str_starts_with($uri, 'http'))) {
      return NULL;
    }
    return $uri;
  }

}
