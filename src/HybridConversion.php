<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Carries layouts stored in custom mode over to a field made hybrid.
 *
 * Turning "allow custom" off and flagging a region makes a field hybrid, and
 * silently hides every layout its entities saved while it was custom: those
 * rows hold a whole tree under uuids of their own, and hybrid mode looks for
 * region content under the uuids of the field default layout. Nothing is
 * lost, nothing renders. This rewrites each row into the subset hybrid mode
 * reads, where the row's locked structure matches the default's, and reports
 * the rows it had to leave alone.
 *
 * Rows are read and written directly, as InertComponentData does: loading an
 * entity would already have composed the row through the mode being
 * converted to, and saving one would write through it.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::convertToHybridStorage()
 */
final class HybridConversion {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Converts, or reports what converting would do to, a field's stored rows.
   *
   * @param string $entityTypeId
   *   The entity type.
   * @param string $fieldName
   *   The component tree field.
   * @param string|null $bundle
   *   One bundle, or NULL for every bundle carrying the field.
   * @param bool $apply
   *   FALSE reports only. TRUE writes the converted rows.
   *
   * @return array
   *   One row per stored row (per revision and language), each with
   *   'bundle', 'entity_id', 'revision_id', 'langcode', 'status' and
   *   'detail'. Status is 'converted', 'already', 'mismatch' or 'skipped'
   *   (the bundle's field is not hybrid; 'entity_id' is then empty).
   */
  public function convert(string $entityTypeId, string $fieldName, ?string $bundle = NULL, bool $apply = FALSE): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $storageDefinition = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId)[$fieldName] ?? NULL;
    if (!$storage instanceof SqlEntityStorageInterface || !$storageDefinition) {
      throw new \InvalidArgumentException(sprintf('%s.%s is not a stored field.', $entityTypeId, $fieldName));
    }
    $tableMapping = $storage->getTableMapping();
    $treeColumn = $tableMapping->getFieldColumnName($storageDefinition, 'tree');
    $propsColumn = $tableMapping->getFieldColumnName($storageDefinition, 'props');
    $tables = [$tableMapping->getFieldTableName($fieldName)];
    if ($tableMapping instanceof DefaultTableMapping && $storageDefinition->isRevisionable()) {
      $tables[] = $tableMapping->getDedicatedRevisionTableName($storageDefinition);
    }

    $report = [];
    $changed = [];
    $bundles = $bundle !== NULL ? [$bundle] : array_keys($this->bundleInfo->getBundleInfo($entityTypeId));
    foreach ($bundles as $bundleId) {
      $field = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundleId)[$fieldName] ?? NULL;
      if (!$field instanceof ComponentFieldConfig) {
        continue;
      }
      if (!$field->isHybrid()) {
        $report[] = $this->row($bundleId, 'skipped', 'The field is not hybrid here: turn off "allow custom" and flag a region as customizable first.');
        continue;
      }
      $defaults = $field->getComponentValues();
      $anchors = $field->getCustomRegions();
      foreach (array_unique(array_filter($tables)) as $table) {
        if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, 'bundle')) {
          continue;
        }
        $rows = $this->database->select($table, 't')
          ->fields('t')
          ->condition('t.bundle', $bundleId)
          ->execute()
          ->fetchAll(FetchAs::Associative);
        foreach ($rows as $raw) {
          $tree = !empty($raw[$treeColumn]) ? json_decode($raw[$treeColumn], TRUE) : NULL;
          $props = !empty($raw[$propsColumn]) ? json_decode($raw[$propsColumn], TRUE) : [];
          if (!is_array($tree)) {
            continue;
          }
          $result = ComponentTreeStructure::convertToHybridStorage($defaults, $tree, is_array($props) ? $props : [], $anchors);
          $detail = match ($result['status']) {
            'converted' => $this->describe($result['tree'], $anchors),
            'mismatch' => $result['reason'],
            default => '',
          };
          $report[] = $this->row($bundleId, $result['status'], $detail, $raw);
          if ($apply && $result['status'] === 'converted') {
            $update = $this->database->update($table)
              ->fields([
                $treeColumn => json_encode($result['tree']),
                $propsColumn => json_encode($result['props']),
              ])
              ->condition('entity_id', $raw['entity_id'])
              ->condition('delta', $raw['delta'])
              ->condition('langcode', $raw['langcode']);
            if (array_key_exists('revision_id', $raw)) {
              $update->condition('revision_id', $raw['revision_id']);
            }
            $update->execute();
            $changed[$raw['entity_id']] = $raw['entity_id'];
          }
        }
      }
    }

    if ($changed) {
      $storage->resetCache(array_values($changed));
      $this->cacheTagsInvalidator->invalidateTags(array_map(
        fn ($id): string => $entityTypeId . ':' . $id,
        array_values($changed),
      ));
      $this->cache->delete(ComponentUsage::CID);
    }
    return $report;
  }

  /**
   * One report row.
   */
  private function row(string $bundle, string $status, string $detail, array $raw = []): array {
    return [
      'bundle' => $bundle,
      'entity_id' => $raw['entity_id'] ?? '',
      'revision_id' => $raw['revision_id'] ?? '',
      'langcode' => $raw['langcode'] ?? '',
      'status' => $status,
      'detail' => $detail,
    ];
  }

  /**
   * Says what a converted row carries: components per customizable region.
   */
  private function describe(array $tree, array $anchors): string {
    $parts = [];
    foreach ($anchors as $ownerUuid => $anchor) {
      foreach ($anchor['slots'] ?? [] as $slotId) {
        $parts[] = sprintf('%s: %d', $slotId, count($tree[$ownerUuid][$slotId] ?? []));
      }
    }
    return implode(', ', $parts);
  }

}
