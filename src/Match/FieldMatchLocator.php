<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Match;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

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
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface|null
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
   * Only ::search() still consumes this: a global search genuinely needs the
   * flattened tree. ::browse() and ::label() resolve one node at a time via
   * ::getNode(), so nothing pays for the walk until someone types a query.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
    $cid = $this->cid($shape, $all, $entityTypeId, $bundle);
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
   * The cache id for a shape's match list.
   *
   * The target segment is asked of the matcher rather than recomputed here.
   * The override contract is subtle — an entity type override takes its bundle
   * from the override too, and a bundle passed without an entity type is
   * ignored — and a second copy of that reasoning drifted from the first:
   * entries were filed under a target the match would never actually use, so
   * whichever picker warmed the cache first decided what the other one offered.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape to match against.
   * @param bool $all
   *   Whether every field is offered.
   * @param string|null $entityTypeId
   *   The entity type override.
   * @param string|null $bundle
   *   The bundle override.
   *
   * @return string
   *   The cache id.
   */
  private function cid(ComponentShapePluginInterface $shape, bool $all, ?string $entityTypeId, ?string $bundle): string {
    return implode(':', [
      'neo_alchemist.field_match',
      $this->matcherField->resolveTarget($shape, $entityTypeId, $bundle)?->getDataType() ?? '',
      $shape->getRef(),
      $shape->getFormat(),
      $shape->isRequired() ? '1' : '0',
      $all ? 'all' : 'matched',
    ]);
  }

  /**
   * One node of the entity tree, computed lazily and cached.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape to match against.
   * @param string[] $hops
   *   Reference field names leading to the node, [] for the root.
   * @param bool $all
   *   Whether every field is offered.
   * @param string|null $entityTypeId
   *   The entity type override.
   * @param string|null $bundle
   *   The bundle override.
   *
   * @return array|null
   *   MatcherField::getNodeMatches()'s structure with leaves reduced to
   *   scalars, or NULL when the path does not resolve. Unresolvable paths are
   *   not negative-cached: the endpoint sits behind an admin permission, and a
   *   probe per bogus path must not be able to fill the bin.
   */
  private function getNode(ComponentShapePluginInterface $shape, array $hops, bool $all, ?string $entityTypeId, ?string $bundle): ?array {
    $cid = $this->cid($shape, $all, $entityTypeId, $bundle) . ':node:' . implode('.', $hops);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $node = $this->matcherField->getNodeMatches($shape, $hops, $entityTypeId, $bundle, $all);
    if ($node === NULL) {
      return NULL;
    }
    // Same reduction as ::getMatches(): live field definition objects have no
    // place in a cache bin, and nothing downstream of the picker reads them.
    $node['leaves'] = array_map(static fn (array $match): array => [
      'title' => (string) $match['title'],
      'group' => (string) $match['group'],
    ], $node['leaves']);
    $this->cache->set($cid, $node, time() + self::CACHE_LIFETIME, [
      'entity_field_info',
      'entity_types',
      'entity_bundles',
    ]);
    return $node;
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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * Computed from that node alone — MatcherField::getNodeMatches() follows the
   * hops and matches one entity level with recursion off — rather than by
   * regrouping the flat recursive walk. The walk's cost is the cartesian
   * product of every reference path to ::$maxLevels, and it was paid before
   * the first column could paint; a node is one entity's field list, whatever
   * the site's content model looks like. It also unties the browser's depth
   * from the walk's: panes exist wherever the hops resolve, so a reference can
   * be followed past the depth search flattens to.
   *
   * One visible consequence: a reference is offered as a doorway whether or
   * not anything behind it can supply the prop — the pane no longer knows, and
   * a hop can dead-end in "nothing here". That is ordinary column-browser
   * behavior (an empty folder is still a folder), and the doorway predicate
   * still only opens references the recursive walk would descend.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
    $prefix = $path === '' ? [] : explode('.', $path);
    $node = $this->getNode($shape, $prefix, $all, $entityTypeId, $bundle);
    if ($node === NULL) {
      // A path that leads nowhere is an empty pane, not an error: the panes a
      // stale browser still shows may address references a field update has
      // since removed.
      return [
        'path' => $path,
        'entity' => '',
        'crumbs' => [],
        'leaves' => [],
        'refs' => [],
      ];
    }

    $leaves = [];
    foreach ($node['leaves'] as $key => $match) {
      $leaves[] = [
        'value' => $key,
        'label' => $match['title'],
        // Kept, not stripped: three alphabetical runs concatenated read as
        // no order at all, so the column draws a boundary where this
        // changes.
        'tier' => $this->tier($key),
      ];
    }
    usort($leaves, static fn (array $a, array $b) => [$a['tier'], $a['label']] <=> [$b['tier'], $b['label']]);

    $refs = [];
    foreach ($node['refs'] as $ref) {
      $refs[] = [
        'path' => $path === '' ? $ref['segment'] : "$path.{$ref['segment']}",
        'segment' => $ref['segment'],
        'label' => $ref['label'],
        'target' => $ref['target'],
      ];
    }
    usort($refs, static fn (array $a, array $b) => $a['label'] <=> $b['label']);

    // The root crumb names the entity; every crumb after it names the hop that
    // reached the level, with the entity as its subtitle. Naming them all by
    // entity instead reads as "Taxonomy Term / Taxonomy Term" the moment a
    // reference points back at its own type, which `parent` always does.
    $crumbs = [];
    $crumbPath = '';
    foreach ($node['crumbs'] as $i => $crumb) {
      if ($i > 0) {
        $crumbPath = $crumbPath === '' ? $prefix[$i - 1] : $crumbPath . '.' . $prefix[$i - 1];
      }
      $crumbs[] = [
        'path' => $crumbPath,
        'label' => $crumb['label'],
        'entity' => $crumb['entity'],
      ];
    }

    return [
      'path' => $path,
      'entity' => $node['entity'],
      'crumbs' => $crumbs,
      'leaves' => $leaves,
      'refs' => $refs,
    ];
  }

  /**
   * The human label for a single stored match key.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
    if ($key === '') {
      return NULL;
    }
    // Resolved from the key's own node rather than the full walk: rendering a
    // form with a stored value must not pay for the whole tree, and a key
    // picked from a pane deeper than the walk flattens to must still label
    // and validate.
    $hops = explode('.', $key);
    array_pop($hops);
    $node = $this->getNode($shape, $hops, $all, $entityTypeId, $bundle);
    $match = $node['leaves'][$key] ?? NULL;
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
