<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Base for the module's route access checkers.
 *
 * Every one of these checkers reads a dot-separated requirement string off the
 * route, splits it, treats some segments as route parameter *names* to resolve
 * and the rest as literal values, and hands the result to a single decision.
 * That parse was written out once per checker, with the arity varying between
 * two and three segments, one checker padding its string to fit, and the
 * positional meaning recorded nowhere — a route author had to open the class to
 * learn what the second segment meant.
 *
 * A subclass now declares the requirement key and its segment format, and
 * implements ::checkAccess(). The base owns:
 *
 * - the parse, so a short or missing requirement resolves to NULL segments
 *   rather than an "undefined array key" on the destructure;
 * - the parameter resolution, so a decision method receives objects, not names;
 * - the neutral fallback, so "this route is not about a component" is one
 *   answer given in one place;
 * - **cacheability**, attached by default. Four of the six checkers used to
 *   attach none, which left their results varying by nothing and invalidated by
 *   nothing on admin routes. A checker that needs different dependencies (the
 *   field checker, whose item is not itself cacheable) overrides
 *   ::cacheableDependencies().
 *
 * ## Requirement formats
 *
 * Each checker's format is the ordered list ::segments() returns, so this table
 * is the contract a route author reads instead of the implementation:
 *
 * | Requirement | Format |
 * |---|---|
 * | `_neo_component` | `{component}.operation` |
 * | `_neo_component_field` | `{tree field item}.operation` |
 * | `_neo_component_prop` | `{component}.{prop name}.operation` |
 * | `_neo_component_slot` | `{component}.{slot name}.operation` |
 * | `_neo_entity_component` | `{host entity}.operation` |
 * | `_neo_field_component` | `{entity type id}.{bundle}.operation` |
 *
 * A braced segment is a PARAM: it names a route parameter and reaches
 * ::checkAccess() as that parameter's value, or NULL when the route does not
 * carry it. An unbraced segment is a VALUE and arrives as written.
 */
abstract class ComponentRouteAccessCheckBase implements AccessInterface {

  /**
   * A segment naming a route parameter, resolved to that parameter's value.
   */
  protected const PARAM = 'param';

  /**
   * A segment passed through to the decision as written.
   */
  protected const VALUE = 'value';

  /**
   * The route requirement this checker reads.
   *
   * @return string
   *   The requirement key, e.g. '_neo_component'.
   */
  abstract protected function requirement(): string;

  /**
   * The requirement's segments, in the order they are written.
   *
   * @return array<string, string>
   *   Segment name => static::PARAM or static::VALUE. The names are the keys
   *   ::checkAccess() receives.
   */
  abstract protected function segments(): array;

  /**
   * Decides access for one resolved requirement.
   *
   * @param array $parts
   *   The requirement's segments keyed by the names ::segments() declared.
   *   PARAM segments hold the resolved route parameter (NULL when the route
   *   does not carry it); VALUE segments hold the literal text.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result. Return neutral when the route is not about something
   *   this checker recognises — other checks then decide.
   */
  abstract protected function checkAccess(array $parts, AccountInterface $account): AccessResultInterface;

  /**
   * {@inheritdoc}
   */
  final public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $requirement = $route->getRequirement($this->requirement());
    if ($requirement === NULL) {
      return AccessResult::neutral();
    }

    $format = $this->segments();
    // A requirement shorter than its declared format leaves its trailing
    // segments empty rather than fataling on the destructure — which is what
    // one checker used to buy by appending dots to the string it parsed.
    $written = array_pad(explode('.', $requirement), count($format), '');
    $parameters = $route_match->getParameters();

    $parts = [];
    foreach (array_keys($format) as $index => $name) {
      $segment = $written[$index];
      $parts[$name] = $format[$name] === static::PARAM
        ? ($segment !== '' ? $parameters->get($segment) : NULL)
        : $segment;
    }

    $result = $this->checkAccess($parts, $account);

    if ($result instanceof RefinableCacheableDependencyInterface) {
      foreach ($this->cacheableDependencies($parts) as $dependency) {
        // Only genuinely cacheable dependencies: core's implementation drops
        // an access result to max-age 0 for anything else, so attaching a
        // route parameter that happens not to be cacheable would make the
        // route uncacheable rather than correctly varied.
        if ($dependency instanceof CacheableDependencyInterface) {
          $result->addCacheableDependency($dependency);
        }
      }
    }

    return $result;
  }

  /**
   * The objects this decision depends on.
   *
   * Defaults to every resolved parameter, which is the answer that makes
   * forgetting cacheability impossible rather than the default outcome.
   *
   * @param array $parts
   *   The resolved requirement segments, as passed to ::checkAccess().
   *
   * @return iterable
   *   Candidate dependencies. Anything that is not a
   *   \Drupal\Core\Cache\CacheableDependencyInterface is ignored.
   */
  protected function cacheableDependencies(array $parts): iterable {
    return array_filter($parts, 'is_object');
  }

}
