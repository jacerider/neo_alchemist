<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Url;

/**
 * Recognises Drupal's three non-linking routes.
 *
 * `<nolink>`, `<none>` and `<button>` are routes that deliberately have no
 * path: core's link generator renders them as a plain `<span>` rather than an
 * `<a>`. Menu authors use `<nolink>` for a heading that groups children without
 * being a destination itself.
 *
 * A url shape carries the uri as a string, and `Url::toUriString()` turns such
 * a route into the *truthy* string `route:<nolink>`. Printed through
 * `neo_uri()` that resolves to the empty string, so the template emits
 * `<a href="">` — which every browser treats as a link to the current page.
 * The bare form (`<nolink>`, as a component author writes it in an
 * `examples:` block) is not a valid uri at all and falls through to
 * `neo_uri()`'s `/` fallback, i.e. the front page.
 *
 * Both are wrong for the same reason: the value is not a url. Producers of a
 * url shape run their uri through ::isNonLinking() and emit the EMPTY URI
 * instead. Templates then branch on `{% if item.url.uri %}` — see the `menu`
 * prop-def scaffold in neo_alchemist.neo_component_prop_defs.yml. Inside an
 * array (a menu item) the shape pipeline goes on to drop the now-empty `url`
 * object altogether, so twig may see no `url` key at all; the same guard
 * covers both, which is why it is written against `url.uri` and never against
 * `url` (an object, and so always truthy).
 *
 * That blank is the empty string, not NULL. The `uri` prop-def is
 * `type: string`, and SDC prop validation rejects a NULL outright:
 * "[front:footer_s1/menu[2].url.uri] NULL value found, but a string is
 * required" — which white-screens the whole component rather than degrading
 * the one link.
 *
 * Emptying a url has a second consequence worth knowing: ArrayShape::
 * buildValue() unsets an entire array item whose REQUIRED child resolves
 * empty. `url` is therefore deliberately absent from the `menu` prop-def's
 * `items.required` list — while it was listed there, a <nolink> column
 * heading and every child beneath it silently vanished from the menu.
 *
 * @see \Drupal\neo\SlideMenu::isNonLinkingUrl()
 * @see \Drupal\Core\Utility\LinkGenerator::generate()
 * @see \Drupal\Tests\neo_alchemist\Kernel\MenuValueTest
 */
final class NonLinkingUri {

  /**
   * The route names that never produce a path.
   */
  public const ROUTES = ['<nolink>', '<none>', '<button>'];

  /**
   * Checks whether a url or uri is one of the non-linking routes.
   *
   * @param mixed $url
   *   A \Drupal\Core\Url object, or a uri string in either the routed form
   *   (`route:<nolink>`) or the bare form (`<nolink>`). Anything else — an
   *   ordinary uri, NULL, an array — answers FALSE.
   *
   * @return bool
   *   TRUE when the value names a non-linking route.
   */
  public static function isNonLinking(mixed $url): bool {
    if ($url instanceof Url) {
      return $url->isRouted() && in_array($url->getRouteName(), self::ROUTES, TRUE);
    }
    if (!is_string($url) || $url === '') {
      return FALSE;
    }
    $route = str_starts_with($url, 'route:') ? substr($url, strlen('route:')) : $url;
    // Route parameters are appended as a query string (`route:foo;a=1`); none
    // of these three routes take any, but strip them so a hand-written value
    // still matches.
    [$route] = explode(';', $route, 2);
    return in_array($route, self::ROUTES, TRUE);
  }

  /**
   * Blanks a uri that names a non-linking route, passing anything else through.
   *
   * @param mixed $uri
   *   The uri to normalise.
   *
   * @return mixed
   *   The empty string when the uri is non-linking (see the class docblock for
   *   why not NULL), the uri unchanged otherwise.
   */
  public static function normalize(mixed $uri): mixed {
    return self::isNonLinking($uri) ? '' : $uri;
  }

  /**
   * Resolves a Url object to its uri string, blanking the non-linking routes.
   *
   * The counterpart to ::normalize() for producers that hold a Url object
   * rather than a uri string.
   *
   * @param \Drupal\Core\Url $url
   *   The url to resolve.
   *
   * @return string
   *   The uri string, or the empty string for a non-linking route.
   */
  public static function toUriString(Url $url): string {
    return self::isNonLinking($url) ? '' : $url->toUriString();
  }

}
