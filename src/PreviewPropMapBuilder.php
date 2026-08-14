<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Template\Attribute;

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
 * Hint paths are joined with `~` to shape ids (nested path with the owning
 * row's delta appended last, matching ComponentShapePluginBase::id()), so a
 * hint that has no shape of its own attaches to its closest owning shape —
 * a link's `title` text lands on the link prop, an image's `alt` on the
 * image prop.
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
        'hints' => ['text' => [], 'src' => [], 'href' => []],
      ];
    }

    $props = $renderable['#props'] ?? [];
    unset($props['attributes'], $props['neoId'], $props['neoUuid'], $props['neoIsPreview']);
    $prefix = $component->isAggregate() ? ['_aggregate'] : [];
    foreach ($props as $name => $value) {
      static::walk($shapes, $value, array_merge($prefix, [(string) $name]), NULL);
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
   * @param int|null $delta
   *   The nearest enclosing iterable row index, if any.
   */
  private static function walk(array &$shapes, mixed $value, array $namePath, ?int $delta): void {
    if ($value instanceof Attribute) {
      // Presentational; already stamped server-side.
      return;
    }
    if (is_string($value) || $value instanceof MarkupInterface) {
      static::addHint($shapes, $namePath, $delta, 'text', trim(strip_tags((string) $value)));
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
      static::addHint($shapes, $namePath, $delta, 'src', $basename);
    }
    foreach (['uri', 'url'] as $key) {
      if (isset($value[$key]) && is_string($value[$key])) {
        static::addHint($shapes, $namePath, $delta, 'href', static::normalizeHref($value[$key]));
      }
    }

    foreach ($value as $key => $item) {
      if (is_int($key)) {
        static::walk($shapes, $item, $namePath, $key);
      }
      else {
        static::walk($shapes, $item, array_merge($namePath, [(string) $key]), $delta);
      }
    }
  }

  /**
   * Attaches a hint to the closest shape owning the walked path.
   *
   * Tries the delta-suffixed id first (deltas sit last on shape ids), then
   * the bare id, then strips trailing path segments — so hints for value
   * keys that are not shapes of their own climb to their owning shape.
   */
  private static function addHint(array &$shapes, array $namePath, ?int $delta, string $type, ?string $hint): void {
    if ($hint === NULL || $hint === '' || mb_strlen($hint) > self::MAX_TEXT_HINT_LENGTH) {
      return;
    }
    $path = $namePath;
    while ($path) {
      $candidates = [];
      if ($delta !== NULL) {
        $candidates[] = implode('~', $path) . '~' . $delta;
      }
      $candidates[] = implode('~', $path);
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
