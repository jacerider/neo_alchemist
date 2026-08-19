<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Reports where each component is used.
 *
 * A component can be placed in three unrelated storages, and a site builder
 * needs all three before it is safe to change or delete one:
 * - content: a neo_component_tree field on a node, term, menu link, …
 * - default layouts: a field config's "settings.defaults.tree".
 * - Alchemist blocks: the neo_alchemist_block config entity's tree.
 *
 * Component ids appear verbatim as "component" keys in a tree's tuples,
 * regardless of which storage holds it, so one collector on the tree seam
 * covers all three.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::collectComponentIds()
 */
final class ComponentUsage {

  use StringTranslationTrait;

  /**
   * The cache id of the usage tally.
   */
  public const CID = 'neo_alchemist:component_usage';

  /**
   * Cache tag for usage facts no entity or config tag covers.
   *
   * Purging inert rows writes no entity and no config, so none of the list
   * tags below fire — this is what a purge invalidates.
   *
   * @see \Drupal\neo_alchemist\InertComponentData::purge()
   */
  public const CACHE_TAG = 'neo_alchemist_component_usage';

  /**
   * Memoized usage tally.
   *
   * @var array|null
   */
  protected ?array $counts = NULL;

  /**
   * Constructs a ComponentUsage object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected ModuleHandlerInterface $moduleHandler,
    protected ComponentSubgroupResolver $subgroupResolver,
    protected InertComponentData $inertData,
  ) {}

  /**
   * Extracts component ids from an onDependencyRemoval() dependency list.
   *
   * @param array $dependencies
   *   The affected dependencies, keyed by type then config name.
   *
   * @return string[]
   *   The component config entity ids being removed.
   */
  public static function componentIdsFromDependencies(array $dependencies): array {
    $prefix = 'neo_alchemist.neo_component.';
    $componentIds = [];
    foreach (array_keys($dependencies['config'] ?? []) as $name) {
      if (str_starts_with((string) $name, $prefix)) {
        $componentIds[] = substr((string) $name, strlen($prefix));
      }
    }
    return $componentIds;
  }

  /**
   * Gets the number of places each component is used.
   *
   * @return array
   *   Keyed by component id, each an array with 'content', 'default', 'block'
   *   and 'total' counts. Components that are used nowhere are absent.
   */
  public function getCounts(): array {
    if ($this->counts !== NULL) {
      return $this->counts;
    }
    if ($cached = $this->cache->get(self::CID)) {
      return $this->counts = $cached->data;
    }

    $counts = [];
    $tally = function (array $componentIds, string $type) use (&$counts): void {
      foreach ($componentIds as $componentId) {
        $counts[$componentId] ??= ['content' => 0, 'default' => 0, 'block' => 0, 'total' => 0];
        $counts[$componentId][$type]++;
        $counts[$componentId]['total']++;
      }
    };

    foreach ($this->scanContent() as $usage) {
      $tally($usage['components'], 'content');
    }
    foreach ($this->scanDefaultLayouts() as $usage) {
      $tally($usage['components'], 'default');
    }
    foreach ($this->scanBlocks() as $usage) {
      $tally($usage['components'], 'block');
    }

    $this->cache->set(self::CID, $counts, CacheBackendInterface::CACHE_PERMANENT, $this->getCacheTags());
    return $this->counts = $counts;
  }

  /**
   * Gets the total usage count of a single component.
   *
   * @param string $componentId
   *   The component config entity id.
   *
   * @return int
   *   The number of places the component is used.
   */
  public function getCount(string $componentId): int {
    return $this->getCounts()[$componentId]['total'] ?? 0;
  }

  /**
   * Gets where a single component is used.
   *
   * @param string $componentId
   *   The component config entity id.
   *
   * @return array
   *   An array keyed by 'content', 'default' and 'block'. Each value is a list
   *   of ['label' => string, 'url' => \Drupal\Core\Url|null, 'context' =>
   *   string] arrays.
   */
  public function getUsages(string $componentId): array {
    $usages = ['content' => [], 'default' => [], 'block' => [], 'inert' => []];
    $scans = [
      'content' => $this->scanContent(),
      'default' => $this->scanDefaultLayouts(),
      'block' => $this->scanBlocks(),
      // Reported, never counted: inert rows are stored but nothing renders
      // them, so they are not usage. ::getCounts() deliberately ignores them.
      'inert' => $this->inertData->scan(),
    ];
    foreach ($scans as $type => $found) {
      foreach ($found as $usage) {
        if (in_array($componentId, $usage['components'], TRUE)) {
          unset($usage['components']);
          $usages[$type][] = $usage;
        }
      }
    }
    return $usages;
  }

  /**
   * The cache tags that invalidate the usage tally.
   *
   * @return string[]
   *   The cache tags.
   */
  public function getCacheTags(): array {
    $tags = [self::CACHE_TAG];
    foreach (array_keys($this->entityFieldManager->getFieldMapByFieldType('neo_component_tree')) as $entityTypeId) {
      if ($definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE)) {
        $tags = array_merge($tags, $definition->getListCacheTags());
      }
    }
    if ($definition = $this->entityTypeManager->getDefinition('field_config', FALSE)) {
      $tags = array_merge($tags, $definition->getListCacheTags());
    }
    if ($this->moduleHandler->moduleExists('neo_alchemist_block') && ($definition = $this->entityTypeManager->getDefinition('neo_alchemist_block', FALSE))) {
      $tags = array_merge($tags, $definition->getListCacheTags());
    }
    return array_values(array_unique($tags));
  }

  /**
   * Scans content entities for component tree field values.
   *
   * @return array
   *   A list of usages, each with 'label', 'url', 'context' and 'components'.
   */
  protected function scanContent(): array {
    $usages = [];
    $fieldMap = $this->entityFieldManager->getFieldMapByFieldType('neo_component_tree');
    foreach ($fieldMap as $entityTypeId => $fields) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      // Some hosts exist only to hang a component field off (Alchemist blocks
      // use ContentEntityNullStorage) and have no table to scan. Their trees
      // are covered by ::scanBlocks().
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
      $tableMapping = $storage->getTableMapping();
      $storageDefinitions = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId);

      foreach ($fields as $fieldName => $fieldInfo) {
        $storageDefinition = $storageDefinitions[$fieldName] ?? NULL;
        if (!$storageDefinition) {
          continue;
        }
        // A locked field's row is never read back — ::setValue() discards it
        // and renders the field default instead — so whatever sits in the
        // column is not usage. Historically it is a snapshot of the default
        // layout at insert time, which would otherwise report the field's own
        // components as content usage, and keep reporting them after the
        // layout moved on. allow_custom is a field *instance* setting, so this
        // is per bundle: one table can hold both locked and customizable rows.
        $bundles = $fieldInfo['bundles'] ?? [];
        $lockedBundles = $this->getLockedBundles($entityTypeId, $fieldName, $bundles);
        if ($lockedBundles && count($lockedBundles) === count($bundles)) {
          continue;
        }
        $table = $tableMapping->getFieldTableName($fieldName);
        $column = $tableMapping->getFieldColumnName($storageDefinition, 'tree');
        if (!$table || !$column || !$this->database->schema()->tableExists($table)) {
          continue;
        }
        // Dedicated field tables key on entity_id and soft-delete rows; a
        // shared table keys on the entity type's own id column. Ask the schema
        // rather than the table mapping, whose storage-layout methods are not
        // on TableMappingInterface.
        $schema = $this->database->schema();
        $idColumn = $schema->fieldExists($table, 'entity_id') ? 'entity_id' : $entityType->getKey('id');

        $select = $this->database->select($table, 't');
        $select->addField('t', $idColumn, 'entity_id');
        $select->addField('t', $column, 'tree');
        if ($schema->fieldExists($table, 'deleted')) {
          $select->condition('t.deleted', 0);
        }
        $select->isNotNull('t.' . $column);
        // Dedicated field tables carry the bundle, so locked rows can be left
        // in the database. A shared table cannot be filtered here; those rows
        // are dropped after load, below.
        $filterLockedAfterLoad = (bool) $lockedBundles;
        if ($lockedBundles && $schema->fieldExists($table, 'bundle')) {
          $select->condition('t.bundle', $lockedBundles, 'NOT IN');
          $filterLockedAfterLoad = FALSE;
        }

        $byEntity = [];
        foreach ($select->execute() as $record) {
          $tree = $record->tree ? json_decode($record->tree, TRUE) : NULL;
          if (!is_array($tree)) {
            continue;
          }
          $byEntity[$record->entity_id] = array_unique(array_merge(
            $byEntity[$record->entity_id] ?? [],
            ComponentTreeStructure::collectComponentIds($tree)
          ));
        }
        if (!$byEntity) {
          continue;
        }

        $entities = $storage->loadMultiple(array_keys($byEntity));
        foreach ($byEntity as $entityId => $componentIds) {
          $entity = $entities[$entityId] ?? NULL;
          if (!$entity) {
            continue;
          }
          if ($filterLockedAfterLoad && in_array($entity->bundle(), $lockedBundles, TRUE)) {
            continue;
          }
          $url = NULL;
          if ($entity->hasLinkTemplate('canonical')) {
            $url = $entity->toUrl('canonical');
          }
          elseif ($entity->hasLinkTemplate('edit-form')) {
            $url = $entity->toUrl('edit-form');
          }
          $usages[] = [
            'label' => (string) $entity->label(),
            'url' => $url,
            'context' => (string) $this->t('@type · @field', [
              '@type' => $entityType->getLabel(),
              '@field' => $fieldName,
            ]),
            'components' => array_values($componentIds),
          ];
        }
      }
    }
    return $usages;
  }

  /**
   * Scans field configs for default layout component trees.
   *
   * @return array
   *   A list of usages, each with 'label', 'url', 'context' and 'components'.
   */
  protected function scanDefaultLayouts(): array {
    $usages = [];
    /** @var \Drupal\field\FieldConfigInterface $field */
    foreach ($this->entityTypeManager->getStorage('field_config')->loadMultiple() as $field) {
      if ($field->getType() !== 'neo_component_tree') {
        continue;
      }
      $tree = $field->getSetting('defaults')['tree'] ?? [];
      if (!is_array($tree) || !$tree) {
        continue;
      }
      $componentIds = ComponentTreeStructure::collectComponentIds($tree);
      if (!$componentIds) {
        continue;
      }
      $usages[] = [
        'label' => (string) $field->label(),
        'url' => $this->getDefaultLayoutUrl($field),
        'context' => $this->subgroupResolver->resolveTargetEntity(
          $field->getTargetEntityTypeId(),
          $field->getTargetBundle()
        )['label'],
        'components' => $componentIds,
      ];
    }
    return $usages;
  }

  /**
   * Scans Alchemist block config entities for component trees.
   *
   * @return array
   *   A list of usages, each with 'label', 'url', 'context' and 'components'.
   */
  protected function scanBlocks(): array {
    if (!$this->moduleHandler->moduleExists('neo_alchemist_block')) {
      return [];
    }
    $usages = [];
    /** @var \Drupal\neo_alchemist_block\AlchemistBlockInterface $block */
    foreach ($this->entityTypeManager->getStorage('neo_alchemist_block')->loadMultiple() as $block) {
      $componentIds = ComponentTreeStructure::collectComponentIds($block->getComponentValues()['tree'] ?? []);
      if (!$componentIds) {
        continue;
      }
      $usages[] = [
        'label' => (string) $block->label(),
        'url' => $block->hasLinkTemplate('edit-form') ? $block->toUrl('edit-form') : NULL,
        'context' => (string) $this->t('Alchemist block'),
        'components' => $componentIds,
      ];
    }
    return $usages;
  }

  /**
   * Finds the bundles where a component tree field is locked.
   *
   * Locked is the absence of the two modes that let an entity own content:
   * allow_custom (the entity owns the whole tree) and hybrid (the entity owns
   * the flagged regions). A locked field renders its default layout and
   * ignores the stored row entirely.
   *
   * @param string $entityTypeId
   *   The entity type id.
   * @param string $fieldName
   *   The field name.
   * @param string[] $bundles
   *   The bundles the field is attached to.
   *
   * @return string[]
   *   The bundles in which the field is locked.
   */
  protected function getLockedBundles(string $entityTypeId, string $fieldName, array $bundles): array {
    $locked = [];
    foreach ($bundles as $bundle) {
      $definition = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle)[$fieldName] ?? NULL;
      if (!$definition instanceof ComponentFieldConfigInterface) {
        // hook_entity_bundle_field_info_alter() normally swaps the class in;
        // rebuild it the same way if something bypassed that.
        if (!$definition instanceof FieldConfigInterface) {
          continue;
        }
        try {
          $definition = new ComponentFieldConfig($definition->toArray(), 'field_config');
        }
        catch (\Exception) {
          continue;
        }
      }
      if (!$definition->allowCustom() && !$definition->isHybrid()) {
        $locked[] = $bundle;
      }
    }
    return $locked;
  }

  /**
   * Builds the manage URL of a field's default layout.
   *
   * @param \Drupal\field\FieldConfigInterface $field
   *   The field config.
   *
   * @return \Drupal\Core\Url|null
   *   The URL, or NULL when it cannot be generated.
   */
  protected function getDefaultLayoutUrl($field): ?Url {
    // The Alchemist link templates live on ComponentFieldConfig, which is
    // swapped in for field *definitions* only — a field_config loaded from
    // storage is a plain FieldConfig. Rebuild it the same way the module's
    // hook_entity_bundle_field_info_alter() does.
    try {
      $componentField = $field instanceof ComponentFieldConfig
        ? $field
        : new ComponentFieldConfig($field->toArray(), 'field_config');
      return $componentField->toUrl();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Invalidates the memoized and cached tally.
   */
  public function reset(): void {
    $this->counts = NULL;
    $this->cache->delete(self::CID);
  }

}
