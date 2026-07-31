<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Addresses a shape by scalars, so a search request can rebuild it.
 *
 * The field picker lives in a form, but its options come from an AJAX route
 * that has no form state. Both ends therefore need to arrive at the same
 * ComponentShape from the same handful of scalars — component id, prop name,
 * nested shape id — rather than from a serialized shape object, which carries
 * live entity references and cannot survive the form cache intact.
 *
 * That shared addressing is also the security boundary. The route decides
 * which of a site's fields are offered, and MatcherField::EXCLUDED_FIELD_TYPES
 * exists because that list can otherwise include password hashes. Every entry
 * point resolves through a real component and its access check.
 *
 * The entity type and bundle CAN be overridden per call, because some
 * providers legitimately match against a different entity than the component
 * targets — an entity_query sorts by fields of the type it queries. That widens
 * what a request may enumerate to "field definitions of any entity type", which
 * is why the route also demands `administer neo_alchemist`: field names are
 * already fully visible to that permission through Field UI, and the exclusion
 * list still applies. It is not a channel to field *values*.
 */
final class FieldMatchLocator {

  /**
   * The nested shape id standing for the prop's own root shape.
   */
  public const ROOT = '_root';

  /**
   * How long a computed match list stays cached.
   */
  private const CACHE_LIFETIME = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MatcherField $matcherField,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Resolves a shape from its scalar address.
   *
   * @param string $componentId
   *   The neo_component config entity id.
   * @param string $prop
   *   The prop name.
   * @param string $shapeId
   *   The nested shape id (e.g. "heading~title"), or self::ROOT for the prop's
   *   own shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The shape, or NULL when any part of the address does not resolve.
   */
  public function resolveShape(string $componentId, string $prop, string $shapeId): ?ComponentShapePluginInterface {
    $component = $this->entityTypeManager->getStorage('neo_component')->load($componentId);
    if (!$component instanceof ComponentInterface) {
      return NULL;
    }
    // The picker exposes the component's data model, so seeing it requires the
    // same access as editing the component.
    if (!$component->access('update')) {
      return NULL;
    }
    $propShape = $component->getPropShapes()[$prop] ?? NULL;
    if (!$propShape) {
      return NULL;
    }
    if ($shapeId === self::ROOT || $shapeId === $propShape->id()) {
      return $propShape;
    }
    // getAllShapes() is keyed by the same id() the address uses, so a nested
    // child is a direct lookup rather than a walk.
    return $propShape->getAllShapes(TRUE)[$shapeId] ?? NULL;
  }

  /**
   * The full match list for a shape, cached.
   *
   * MatcherField walks every field of the target entity type and recurses two
   * levels through its references, which is 15-35ms and over a thousand
   * matches on a modestly sized site — per call, with no memoisation of its
   * own. The result depends only on the entity model and the shape's own
   * matching predicate, so it survives until field definitions change.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape to match against.
   * @param bool $all
   *   Whether to offer every field rather than only those the shape supports.
   * @param string|null $entityTypeId
   *   Match against this entity type instead of the shape's target. Needed by
   *   providers whose fields come from somewhere other than the component's
   *   own entity — an entity_query's sort fields belong to the queried type.
   * @param string|null $bundle
   *   Match against this bundle instead of the shape's target bundle.
   *
   * @return array
   *   Match entries keyed by matcher key, as MatcherField::getMatches()
   *   returns them, minus the field definition objects (which do not cache).
   */
  public function getMatches(ComponentShapePluginInterface $shape, bool $all = FALSE, ?string $entityTypeId = NULL, ?string $bundle = NULL): array {
    $cid = implode(':', [
      'neo_alchemist.field_match',
      $entityTypeId ?? $shape->getTargetEntityType() ?? '',
      $bundle ?? $shape->getTargetEntityBundle() ?? '',
      $shape->getRef(),
      $shape->getFormat(),
      $shape->isRequired() ? '1' : '0',
      $all ? 'all' : 'matched',
    ]);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $matches = [];
    foreach ($this->matcherField->getMatches($shape, $entityTypeId, $bundle, NULL, $all) as $key => $match) {
      // Drop the field definition: it is a live object graph that has no place
      // in a cache bin, and nothing downstream of the picker reads it.
      $matches[$key] = [
        'title' => (string) $match['title'],
        'group' => (string) $match['group'],
      ];
    }
    $this->cache->set($cid, $matches, time() + self::CACHE_LIFETIME, [
      'entity_field_info',
      'entity_types',
      'entity_bundles',
    ]);
    return $matches;
  }

  /**
   * Matcher keys that are plumbing rather than content.
   *
   * Ranked below real fields, never removed: a component already configured
   * against one keeps working, and a few of them (langcode, a revision id) are
   * legitimately what someone wants.
   */
  private const SYSTEM_PATTERN = '/(^|[.:])(uuid|langcode|default_langcode|revision_id|revision_log_message|revision_user|revision_uid|revision_created|revision_default|status|changed|created|weight|path|metatag|content_translation_\w+)([.:]|$)/';

  /**
   * Searches a shape's matches, ranked for a picker.
   *
   * Ranking is the point of this method. On a modest site half the match list
   * is noise — a link template for every route the entity type declares, plus
   * revision and language plumbing — while the fields people actually pick are
   * the few dozen sitting directly on the target entity. Without ranking, an
   * empty query opens on "(Link) Alchemist: Link text", because "(" sorts
   * before every letter.
   *
   * Order is: reference hops, then how much like real content the match is,
   * then label. Hops first because a field on the entity itself is nearly
   * always what is meant; a two-hop namesake is a fallback, not a rival.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape to match against.
   * @param string $query
   *   The search string. Whitespace-separated terms are ANDed, and each is
   *   matched against the path, the label and the key together — none of the
   *   three alone identifies a match, since the label carries the field and
   *   the path carries the reference route that reached it.
   * @param bool $all
   *   Whether to offer every field rather than only those the shape supports.
   * @param int $limit
   *   Maximum results to return.
   * @param string|null $entityTypeId
   *   Match against this entity type instead of the shape's target. Needed by
   *   providers whose fields come from somewhere other than the component's
   *   own entity — an entity_query's sort fields belong to the queried type.
   * @param string|null $bundle
   *   Match against this bundle instead of the shape's target bundle.
   *
   * @return array
   *   A list of ['value' => key, 'label' => title, 'path' => group].
   */
  public function search(ComponentShapePluginInterface $shape, string $query = '', bool $all = FALSE, int $limit = 50, ?string $entityTypeId = NULL, ?string $bundle = NULL): array {
    $terms = array_filter(preg_split('/\s+/', trim(mb_strtolower($query))) ?: []);
    $results = [];
    foreach ($this->getMatches($shape, $all, $entityTypeId, $bundle) as $key => $match) {
      $haystack = mb_strtolower($match['group'] . ' ' . $match['title'] . ' ' . $key);
      foreach ($terms as $term) {
        if (!str_contains($haystack, $term)) {
          continue 2;
        }
      }
      $results[] = [
        'value' => $key,
        'label' => $match['title'],
        'path' => $match['group'],
        // Sort keys only; stripped before the response.
        '_hops' => substr_count($key, '.'),
        '_tier' => $this->tier($key),
      ];
    }
    usort($results, static function (array $a, array $b) {
      return [$a['_hops'], $a['_tier'], $a['label']] <=> [$b['_hops'], $b['_tier'], $b['label']];
    });
    $results = array_slice($results, 0, $limit);
    foreach ($results as &$result) {
      unset($result['_hops'], $result['_tier']);
    }
    return $results;
  }

  /**
   * How much like authored content a match is. Lower sorts first.
   *
   * @param string $key
   *   The matcher key.
   *
   * @return int
   *   0 for real content, 1 for system plumbing, 2 for link templates.
   */
  private function tier(string $key): int {
    if (str_contains($key, '_entity:link:')) {
      return 2;
    }
    if (preg_match(self::SYSTEM_PATTERN, $key)) {
      return 1;
    }
    return 0;
  }

  /**
   * One pane of the field browser: what sits at a point in the entity tree.
   *
   * The flat match list is the cartesian view of a tree that is small at every
   * node — 56 rows at the root here, ~37 one hop in, ~30 two hops in. Nothing
   * new has to be matched to get that tree back: the keys already encode it,
   * as dot-separated reference hops ending in a field or property, and the
   * group string carries the entity label reached by each hop. So this is a
   * regrouping of the cached list, not a second traversal.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape to match against.
   * @param string $path
   *   The dot-separated reference path to open, '' for the target entity.
   * @param bool $all
   *   Whether to offer every field rather than only those the shape supports.
   * @param string|null $entityTypeId
   *   Match against this entity type instead of the shape's target. Needed by
   *   providers whose fields come from somewhere other than the component's
   *   own entity — an entity_query's sort fields belong to the queried type.
   * @param string|null $bundle
   *   Match against this bundle instead of the shape's target bundle.
   *
   * @return array
   *   ['path', 'entity', 'crumbs', 'leaves', 'refs'].
   */
  public function browse(ComponentShapePluginInterface $shape, string $path = '', bool $all = FALSE, ?string $entityTypeId = NULL, ?string $bundle = NULL): array {
    $matches = $this->getMatches($shape, $all, $entityTypeId, $bundle);
    $prefix = $path === '' ? [] : explode('.', $path);
    $depth = count($prefix);

    $leaves = [];
    $refs = [];
    $entity = '';
    // Human labels for reference hops, harvested from the hop field's own leaf
    // entry ("Term Parents: Taxonomy term ID (parent)"). Collected across the
    // whole list rather than per node, so an ancestor crumb can be named even
    // though its own pane was never requested. Not every hop has such a leaf —
    // an entity reference is rarely a valid source for a string prop — so this
    // degrades to the machine name rather than depending on it.
    $hopLabels = [];

    foreach ($matches as $key => $match) {
      $parts = explode('.', $key);
      $leaf = array_pop($parts);
      $hop = explode(':', $leaf)[0];
      if (!isset($hopLabels[$hop])) {
        $hopLabels[$hop] = trim(explode(':', $match['title'])[0]);
      }
      if (array_slice($parts, 0, $depth) !== $prefix) {
        continue;
      }
      $segments = explode(' → ', $match['group']);

      if ($parts === $prefix) {
        // A field at this node.
        $entity = $entity ?: ($segments[$depth] ?? '');
        $leaves[] = [
          'value' => $key,
          'label' => $match['title'],
          // Kept, not stripped: three alphabetical runs concatenated read as
          // no order at all, so the column draws a boundary where this
          // changes.
          'tier' => $this->tier($key),
        ];
        continue;
      }

      // Something deeper: the next segment is a reference hop from this node.
      $next = $parts[$depth];
      $hopPath = $path === '' ? $next : "$path.$next";
      if (!isset($refs[$next])) {
        $refs[$next] = [
          'path' => $hopPath,
          'segment' => $next,
          // Filled after the loop: the hop's own leaf may not be seen yet.
          'label' => $next,
          'target' => $segments[$depth + 1] ?? '',
          'count' => 0,
        ];
      }
      $refs[$next]['count']++;
    }

    foreach ($refs as $hop => &$ref) {
      $ref['label'] = $hopLabels[$hop] ?? $hop;
    }
    unset($ref);

    usort($leaves, static fn (array $a, array $b) => [$a['tier'], $a['label']] <=> [$b['tier'], $b['label']]);
    usort($refs, static fn (array $a, array $b) => $a['label'] <=> $b['label']);

    return [
      'path' => $path,
      'entity' => $entity,
      'crumbs' => $this->crumbs($matches, $prefix, $hopLabels),
      'leaves' => $leaves,
      'refs' => array_values($refs),
    ];
  }

  /**
   * The breadcrumb for a reference path.
   *
   * @param array $matches
   *   The full match list.
   * @param array $prefix
   *   The path segments.
   * @param array $hopLabels
   *   Field-name to human-label map for reference hops.
   *
   * @return array
   *   A list of ['path' => …, 'label' => …, 'entity' => …], root first.
   */
  private function crumbs(array $matches, array $prefix, array $hopLabels): array {
    // Any match reaching at least this deep carries the entity label for every
    // level along the way in its group string.
    $segments = [];
    foreach ($matches as $key => $match) {
      $parts = explode('.', $key);
      array_pop($parts);
      if (array_slice($parts, 0, count($prefix)) === $prefix) {
        $segments = explode(' → ', $match['group']);
        break;
      }
    }
    // The root crumb names the entity; every crumb after it names the hop that
    // reached the level, with the entity as its subtitle. Naming them all by
    // entity instead reads as "Taxonomy Term / Taxonomy Term" the moment a
    // reference points back at its own type, which `parent` always does.
    $crumbs = [];
    $path = '';
    $stripEntity = static fn (string $segment): string => preg_replace('/\s*\([^)]*\)$/', '', $segment);
    $crumbs[] = [
      'path' => '',
      'label' => $stripEntity($segments[0] ?? ''),
      'entity' => '',
    ];
    foreach ($prefix as $i => $hop) {
      $path = $path === '' ? $hop : "$path.$hop";
      $crumbs[] = [
        'path' => $path,
        'label' => $hopLabels[$hop] ?? $hop,
        'entity' => $stripEntity($segments[$i + 1] ?? ''),
      ];
    }
    return $crumbs;
  }

  /**
   * The human label for a single stored match key.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape the key belongs to.
   * @param string $key
   *   The matcher key.
   * @param bool $all
   *   Whether the key was offered from the unfiltered list.
   * @param string|null $entityTypeId
   *   Match against this entity type instead of the shape's target. Needed by
   *   providers whose fields come from somewhere other than the component's
   *   own entity — an entity_query's sort fields belong to the queried type.
   * @param string|null $bundle
   *   Match against this bundle instead of the shape's target bundle.
   *
   * @return array|null
   *   ['value' => …, 'label' => …, 'path' => …], or NULL when the key is not
   *   (or is no longer) a valid match for this shape.
   */
  public function label(ComponentShapePluginInterface $shape, string $key, bool $all = FALSE, ?string $entityTypeId = NULL, ?string $bundle = NULL): ?array {
    $match = $this->getMatches($shape, $all, $entityTypeId, $bundle)[$key] ?? NULL;
    if (!$match) {
      return NULL;
    }
    return [
      'value' => $key,
      'label' => $match['title'],
      'path' => $match['group'],
    ];
  }

}
