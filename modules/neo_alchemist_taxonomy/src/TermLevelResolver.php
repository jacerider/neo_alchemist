<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_taxonomy;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves taxonomy term hierarchy levels to Alchemist component fields.
 */
final class TermLevelResolver {

  /**
   * Maximum parent hops when computing a term's level.
   */
  protected const MAX_LEVEL = 50;

  /**
   * Level-managed fields per vocabulary, as [fieldName => level].
   *
   * @var array[]
   */
  protected array $managedFields = [];

  /**
   * Computed levels keyed by term ID.
   *
   * @var int[]
   */
  protected array $levels = [];

  /**
   * Constructs a TermLevelResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * Gets the level-managed component tree fields of a vocabulary.
   *
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return int[]
   *   The hierarchy level of each managed component tree field, keyed by
   *   field name and sorted by level. Empty when the vocabulary has no
   *   level-managed fields.
   */
  public function getManagedFields(string $vid): array {
    if (!isset($this->managedFields[$vid])) {
      $fields = [];
      foreach ($this->entityFieldManager->getFieldDefinitions('taxonomy_term', $vid) as $fieldName => $definition) {
        if ($definition instanceof ComponentFieldConfigInterface) {
          $level = (int) $definition->getThirdPartySetting('neo_alchemist_taxonomy', 'level', 0);
          if ($level > 0) {
            $fields[$fieldName] = $level;
          }
        }
      }
      asort($fields);
      $this->managedFields[$vid] = $fields;
    }
    return $this->managedFields[$vid];
  }

  /**
   * Gets the 1-based hierarchy level of a term.
   *
   * Follows each term's first parent so the result is deterministic in
   * poly-hierarchies and, unlike TermStorage::loadAllParents(), independent
   * of the current user's term access.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   *
   * @return int
   *   The level: 1 for root terms, 2 for their children, etc.
   */
  public function getLevel(TermInterface $term): int {
    $tid = (int) $term->id();
    if ($tid && isset($this->levels[$tid])) {
      return $this->levels[$tid];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $level = 1;
    $visited = [$tid => TRUE];
    $current = $term;
    while ($level < self::MAX_LEVEL) {
      $parentId = (int) ($current->get('parent')->target_id ?? 0);
      if (!$parentId || isset($visited[$parentId])) {
        break;
      }
      $parent = $storage->load($parentId);
      if (!$parent instanceof TermInterface) {
        break;
      }
      $visited[$parentId] = TRUE;
      $level++;
      $current = $parent;
    }
    if ($tid) {
      $this->levels[$tid] = $level;
    }
    return $level;
  }

  /**
   * Gets a representative saved term at a hierarchy level.
   *
   * Used to preview a per-level component tree against real content. Prefers a
   * term that has children so child-driven components (e.g. a child-terms list)
   * are not empty, falling back to the first view-accessible term at the level.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param int $level
   *   The 1-based hierarchy level.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   A representative term, or NULL when the vocabulary has none at the level.
   */
  public function getSampleTermForLevel(string $vid, int $level): ?TermInterface {
    if ($level < 1) {
      return NULL;
    }
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // loadTree() depth is 0-based, so a 1-based level maps to depth level - 1.
    $tree = $storage->loadTree($vid, 0, $level, FALSE);
    $fallback = NULL;
    foreach ($tree as $item) {
      if ((int) $item->depth !== $level - 1) {
        continue;
      }
      $term = $storage->load($item->tid);
      if (!$term instanceof TermInterface || !$term->access('view')) {
        continue;
      }
      $fallback = $fallback ?? $term;
      // Prefer a term that actually has children so the preview isn't empty.
      if ($storage->loadTree($vid, (int) $item->tid, 1, FALSE)) {
        return $term;
      }
    }
    return $fallback;
  }

  /**
   * Gets the component tree field that applies to a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   *
   * @return string|null
   *   The field name whose configured level matches the term's level. Terms
   *   deeper than the deepest configured level get the deepest level's field.
   *   NULL when the vocabulary has no level-managed fields or the term is
   *   shallower than every configured level.
   */
  public function getMatchingFieldName(TermInterface $term): ?string {
    $fields = $this->getManagedFields($term->bundle());
    if (!$fields) {
      return NULL;
    }
    $level = $this->getLevel($term);
    $match = NULL;
    foreach ($fields as $fieldName => $fieldLevel) {
      if ($fieldLevel === $level) {
        return $fieldName;
      }
      if ($fieldLevel < $level) {
        // Fields are sorted by level, so the last one shallower than the term
        // wins when there is no exact match.
        $match = $fieldName;
      }
    }
    return $match;
  }

}
