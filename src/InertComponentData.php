<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Finds and purges stored layout data that nothing renders.
 *
 * A locked field — allow_custom off, no entity-customizable regions — renders
 * its default layout and ignores whatever sits in the entity's row:
 * NeoComponentTreeList::setValue() discards it on load. Rows get there anyway,
 * most often because a site builder turned customization OFF after editors had
 * already customized entities. Nothing reacts to that setting changing, so the
 * trees simply stop rendering and stay in the database.
 *
 * That data is inert but RECOVERABLE: turning customization back on renders it
 * again. Purging is the deliberate discard of that second chance, which is why
 * it is never automatic.
 *
 * Inert means a locked field's row whose tree ROOT is populated. A row with an
 * empty root is a hybrid storage subset — region content authored while the
 * field was hybrid, which re-flagging the region legitimately restores — and is
 * never touched.
 *
 * @see \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList::setValue()
 * @see neo_alchemist_update_11005()
 */
final class InertComponentData {

  use StringTranslationTrait;

  /**
   * State key holding the most recent purge per field, for reversibility.
   */
  public const STATE_KEY = 'neo_alchemist.purged_inert_rows';

  /**
   * Constructs an InertComponentData object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
    protected StateInterface $state,
    protected CacheBackendInterface $cache,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Reports every field carrying inert rows.
   *
   * Rows are per FIELD, not per entity: the stored row belongs to a field
   * instance and can hold many components, so purging is only coherent at
   * field granularity. The shape matches ComponentUsage's other scans so the
   * usage page can render it with the same code.
   *
   * @return array
   *   A list of usages, each with 'label', 'url', 'context' and 'components'.
   */
  public function scan(): array {
    $usages = [];
    foreach ($this->lockedFields() as $locked) {
      $rows = $this->inertRows($locked['entity_type_id'], $locked['bundle'], $locked['field_name'], FALSE);
      if (!$rows) {
        continue;
      }
      $entityIds = [];
      $componentIds = [];
      foreach ($rows as $row) {
        $entityIds[$row['entity_id']] = TRUE;
        $componentIds = array_merge($componentIds, ComponentTreeStructure::collectComponentIds($row['tree']));
      }
      $count = count($entityIds);
      $usages[] = [
        'label' => (string) $this->t('@field (@bundle)', [
          '@field' => $locked['field']->label(),
          '@bundle' => $locked['bundle'],
        ]),
        'url' => $this->getPurgeUrl($locked['field']),
        'context' => (string) $this->formatPlural(
          $count,
          '1 entity · never rendered',
          '@count entities · never rendered',
        ),
        'components' => array_values(array_unique($componentIds)),
      ];
    }
    return $usages;
  }

  /**
   * Counts the entities carrying inert rows for one field.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The field name.
   *
   * @return int
   *   The number of entities, 0 when the field is not locked.
   */
  public function countFor(string $entityTypeId, string $bundle, string $fieldName): int {
    if (!$this->isLocked($entityTypeId, $bundle, $fieldName)) {
      return 0;
    }
    return $this->countStored($entityTypeId, $bundle, $fieldName);
  }

  /**
   * Counts the entities storing a full tree for one field, whatever its mode.
   *
   * The same rows ::countFor() reports, minus the locked check — on a field
   * that still allows customization these are live, rendered layouts. This is
   * what tells a site builder how much they are about to switch off.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The field name.
   *
   * @return int
   *   The number of entities.
   */
  public function countStored(string $entityTypeId, string $bundle, string $fieldName): int {
    $entityIds = [];
    foreach ($this->inertRows($entityTypeId, $bundle, $fieldName, FALSE) as $row) {
      $entityIds[$row['entity_id']] = TRUE;
    }
    return count($entityIds);
  }

  /**
   * Deletes the inert rows of one field, archiving them first.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The field name.
   *
   * @return int
   *   The number of entities whose data was purged.
   */
  public function purge(string $entityTypeId, string $bundle, string $fieldName): int {
    if (!$this->isLocked($entityTypeId, $bundle, $fieldName)) {
      return 0;
    }
    $archive = [];
    $entityIds = [];
    // Both the data and the revision table: leaving revision rows behind would
    // resurrect the data the moment a revision is restored.
    foreach ($this->inertRows($entityTypeId, $bundle, $fieldName, TRUE) as $row) {
      $archive[] = ['table' => $row['table'], 'row' => $row['raw']];
      $delete = $this->database->delete($row['table'])
        ->condition('entity_id', $row['raw']['entity_id'])
        ->condition('langcode', $row['raw']['langcode'])
        ->condition('delta', $row['raw']['delta']);
      if (array_key_exists('deleted', $row['raw'])) {
        $delete->condition('deleted', $row['raw']['deleted']);
      }
      if (array_key_exists('revision_id', $row['raw'])) {
        $delete->condition('revision_id', $row['raw']['revision_id']);
      }
      $delete->execute();
      $entityIds[$row['entity_id']] = TRUE;
    }
    if (!$archive) {
      return 0;
    }

    $archived = $this->state->get(self::STATE_KEY, []);
    $archived[$entityTypeId . '.' . $bundle . '.' . $fieldName] = $archive;
    $this->state->set(self::STATE_KEY, $archived);

    // Nothing was saved, so no entity or config tag fires on its own — the
    // usage report would keep serving the purged rows without this.
    $this->cache->delete(ComponentUsage::CID);
    $this->cacheTagsInvalidator->invalidateTags([ComponentUsage::CACHE_TAG]);

    return count($entityIds);
  }

  /**
   * Checks whether one field instance is locked.
   */
  private function isLocked(string $entityTypeId, string $bundle, string $fieldName): bool {
    $definition = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle)[$fieldName] ?? NULL;
    $field = $this->asComponentField($definition);
    return $field && !$field->allowCustom() && !$field->isHybrid();
  }

  /**
   * Lists every locked component tree field instance on the site.
   *
   * @return array
   *   A list of ['field', 'entity_type_id', 'bundle', 'field_name'].
   */
  private function lockedFields(): array {
    $locked = [];
    foreach ($this->entityTypeManager->getStorage('field_config')->loadMultiple() as $field) {
      if (!$field instanceof FieldConfigInterface || $field->getType() !== 'neo_component_tree') {
        continue;
      }
      $componentField = $this->asComponentField($field);
      if (!$componentField || $componentField->allowCustom() || $componentField->isHybrid()) {
        continue;
      }
      $locked[] = [
        'field' => $componentField,
        'entity_type_id' => $field->getTargetEntityTypeId(),
        'bundle' => $field->getTargetBundle(),
        'field_name' => $field->getName(),
      ];
    }
    return $locked;
  }

  /**
   * Reads the inert rows of one field.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $fieldName
   *   The field name.
   * @param bool $includeRevisions
   *   TRUE to also read the dedicated revision table. Counting wants distinct
   *   entities (data table only); purging must clear both.
   *
   * @return array
   *   A list of ['entity_id', 'tree', 'table', 'raw'].
   */
  private function inertRows(string $entityTypeId, string $bundle, string $fieldName, bool $includeRevisions): array {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    if (!$storage instanceof SqlEntityStorageInterface) {
      return [];
    }
    $storageDefinition = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId)[$fieldName] ?? NULL;
    if (!$storageDefinition) {
      return [];
    }
    $tableMapping = $storage->getTableMapping();
    $column = $tableMapping->getFieldColumnName($storageDefinition, 'tree');
    $tables = [$tableMapping->getFieldTableName($fieldName)];
    if ($includeRevisions && $tableMapping instanceof DefaultTableMapping) {
      $tables[] = $tableMapping->getDedicatedRevisionTableName($storageDefinition);
    }

    $found = [];
    foreach (array_unique(array_filter($tables)) as $table) {
      // A shared table has no bundle column, so a locked bundle's rows cannot
      // be told apart from a customizable one's — skip rather than guess.
      if (!$this->database->schema()->tableExists($table) || !$this->database->schema()->fieldExists($table, 'bundle')) {
        continue;
      }
      $rows = $this->database->select($table, 't')
        ->fields('t')
        ->condition('t.bundle', $bundle)
        ->execute()
        ->fetchAll(FetchAs::Associative);
      foreach ($rows as $row) {
        $tree = !empty($row[$column]) ? json_decode($row[$column], TRUE) : NULL;
        // An empty root means a hybrid subset (or nothing at all) — keep it.
        if (!is_array($tree) || empty($tree[ComponentTreeStructure::ROOT_UUID])) {
          continue;
        }
        $found[] = [
          'entity_id' => $row['entity_id'],
          'tree' => $tree,
          'table' => $table,
          'raw' => $row,
        ];
      }
    }
    return $found;
  }

  /**
   * Rebuilds a field as a ComponentFieldConfig so the mode can be read.
   *
   * The class swap in hook_entity_bundle_field_info_alter() only covers field
   * *definitions*; a field_config loaded from storage is a plain FieldConfig.
   */
  private function asComponentField(mixed $field): ?ComponentFieldConfig {
    if ($field instanceof ComponentFieldConfig) {
      return $field;
    }
    if (!$field instanceof FieldConfigInterface) {
      return NULL;
    }
    try {
      return new ComponentFieldConfig($field->toArray(), 'field_config');
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Builds the purge form URL of a field, if one can be generated.
   */
  private function getPurgeUrl(ComponentFieldConfig $field): ?Url {
    try {
      return $field->toUrl('purge');
    }
    catch (\Exception) {
      return NULL;
    }
  }

}
