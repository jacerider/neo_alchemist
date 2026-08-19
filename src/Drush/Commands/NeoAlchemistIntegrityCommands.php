<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\neo_alchemist\ComponentUsage;
use Drupal\neo_alchemist\DanglingComponentData;
use Drupal\neo_alchemist\EmptySectionPolicy;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds and repairs component placements that point at nothing.
 *
 * A component tree stores the neo_component id as a plain string. Rename or
 * delete the component and every placement keeps that string, resolves it to
 * NULL at render, and is skipped — the page still returns 200, just without
 * that component. These commands exist because that is otherwise invisible.
 */
final class NeoAlchemistIntegrityCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_alchemist.dangling_component_data')]
    private readonly DanglingComponentData $dangling,
    #[Autowire(service: 'neo_alchemist.component_usage')]
    private readonly ComponentUsage $usage,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity_field.manager')]
    private readonly EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'cache_tags.invalidator')]
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    #[Autowire(service: 'renderer')]
    private readonly RendererInterface $renderer,
  ) {
    parent::__construct();
  }

  /**
   * Report component placements whose component no longer exists.
   */
  #[CLI\Command(name: 'neo:alchemist:integrity', aliases: ['neoa-integrity'])]
  #[CLI\Option(name: 'detach', description: 'Remove the dangling placements. Destructive and not undoable — the instances and their children are deleted from the stored trees.')]
  #[CLI\FieldLabels(labels: [
    'component' => 'Missing component',
    'label' => 'Placed on',
    'context' => 'Where',
    'url' => 'URL',
  ])]
  #[CLI\DefaultFields(fields: ['component', 'label', 'context', 'url'])]
  #[CLI\Usage(name: 'drush neo:alchemist:integrity', description: 'List every dangling placement. Exits non-zero when any exist, so it works as a deploy check.')]
  #[CLI\Usage(name: 'drush neo:alchemist:integrity --detach', description: 'Remove them.')]
  public function integrity(array $options = ['detach' => FALSE, 'format' => 'table']): CommandResult|int {
    $found = $this->dangling->scan(TRUE);

    if (!$found) {
      $this->io()->success('No dangling component placements.');
      return self::EXIT_SUCCESS;
    }

    if (empty($options['detach'])) {
      $rows = [];
      foreach ($found as $componentId => $info) {
        foreach ($info['places'] as $delta => $place) {
          $rows[$componentId . ':' . $delta] = [
            'component' => $componentId,
            'label' => $place['label'],
            'context' => $place['context'],
            'url' => $place['url'],
          ];
        }
      }
      $this->io()->warning(sprintf('%d placement(s) reference a component that does not exist. Use `drush neo:alchemist:rename OLD NEW` if it was renamed, or --detach to remove them.', array_sum(array_column($found, 'count'))));
      // dataWithExitCode keeps --format working while still failing: this is
      // meant to be usable as a deploy gate, and a check that always exits 0
      // gates nothing.
      return CommandResult::dataWithExitCode(new RowsOfFields($rows), self::EXIT_FAILURE);
    }

    $ids = array_keys($found);
    $this->io()->warning(sprintf('About to remove every placement of: %s. Their child components go with them, and this cannot be undone.', implode(', ', $ids)));
    if (!$this->io()->confirm('Continue?')) {
      return self::EXIT_FAILURE;
    }

    $rows = $this->detach($ids);
    $this->usage->reset();
    $this->cacheTagsInvalidator->invalidateTags([ComponentUsage::CACHE_TAG, 'rendered']);
    $this->io()->success(sprintf('Detached %d dangling placement(s) from %d row(s).', array_sum(array_column($found, 'count')), $rows));
    return self::EXIT_SUCCESS;
  }

  /**
   * Removes the given component ids from every stored tree.
   *
   * Writes the tree/props pair back through
   * ComponentTreeStructure::detachComponents(), which prunes the instances and
   * everything nested beneath them, so no orphaned children are left behind.
   *
   * These are **entity** rows, and a hybrid field's row is a storage subset
   * rather than a whole tree — so the empty-section policy is decided per row.
   * Collapsing a subset would drop the flagged sections a creator had
   * deliberately emptied, and the next load reads a subset with nothing left
   * in it as "never customized" and repopulates the region with the site
   * builder's seed components. A maintenance command detaching an unrelated
   * deleted component must not rewrite anyone's pages.
   *
   * @return int
   *   The number of rows rewritten.
   */
  private function detach(array $componentIds): int {
    $rows = 0;
    foreach ($this->treeColumns() as [$table, $treeColumn, $propsColumn, $idColumn]) {
      $select = $this->database->select($table, 't')->fields('t');
      $select->condition('t.' . $treeColumn, '%' . $this->database->escapeLike('"component":"') . '%', 'LIKE');
      foreach ($select->execute() as $record) {
        $record = (array) $record;
        $tree = json_decode((string) ($record[$treeColumn] ?? ''), TRUE);
        if (!is_array($tree)) {
          continue;
        }
        $props = json_decode((string) ($record[$propsColumn] ?? ''), TRUE);
        $before = ['tree' => $tree, 'props' => is_array($props) ? $props : []];
        $policy = ComponentTreeStructure::isStorageSubset($tree)
          ? EmptySectionPolicy::Preserve
          : EmptySectionPolicy::Collapse;
        $after = ComponentTreeStructure::detachComponents($before, $componentIds, $policy);
        if ($after === $before) {
          continue;
        }
        $update = $this->database->update($table)
          ->fields([
            $treeColumn => json_encode($after['tree']),
            $propsColumn => json_encode($after['props']),
          ]);
        foreach ($this->rowKeys($table, $record, $idColumn) as $key => $value) {
          $update->condition($key, $value);
        }
        $rows += $update->execute();
      }
    }
    return $rows;
  }

  /**
   * Finds every table holding a component tree, with its column names.
   */
  private function treeColumns(): array {
    $found = [];
    $schema = $this->database->schema();
    foreach ($this->entityFieldManager->getFieldMapByFieldType('neo_component_tree') as $entityTypeId => $fields) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      if (!method_exists($storage, 'getTableMapping')) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId);
      foreach (array_keys($fields) as $fieldName) {
        if (!isset($definitions[$fieldName])) {
          continue;
        }
        $treeColumn = $mapping->getFieldColumnName($definitions[$fieldName], 'tree');
        $propsColumn = $mapping->getFieldColumnName($definitions[$fieldName], 'props');
        foreach ([$mapping->getFieldTableName($fieldName), $mapping->getFieldTableName($fieldName) . '_revision'] as $table) {
          if ($table && $schema->tableExists($table) && $schema->fieldExists($table, $treeColumn)) {
            $idColumn = $schema->fieldExists($table, 'entity_id') ? 'entity_id' : $this->entityTypeManager->getDefinition($entityTypeId)->getKey('id');
            $found[$table] = [$table, $treeColumn, $propsColumn, $idColumn];
          }
        }
      }
    }
    return array_values($found);
  }

  /**
   * Builds a WHERE clause that targets exactly one stored row.
   *
   * Dedicated field tables key on entity_id + revision_id + delta + langcode;
   * a shared table keys on the entity's own id. Matching on the id alone would
   * rewrite every revision and translation of a multi-valued field.
   */
  private function rowKeys(string $table, array $record, string $idColumn): array {
    $keys = [];
    foreach ([$idColumn, 'revision_id', 'delta', 'langcode', 'deleted'] as $candidate) {
      if (array_key_exists($candidate, $record)) {
        $keys[$candidate] = $record[$candidate];
      }
    }
    return $keys;
  }

  /**
   * Rename a component, rewriting every placement that references it.
   *
   * The three things carrying a component id have to move together: the config
   * entity, the trees that place it, and (outside this command) the SDC the
   * entity points at. Renaming the entity alone leaves every placement
   * resolving to NULL, which renders as a blank page and logs nothing — so
   * this does both halves in one step rather than leaving it to discipline.
   */
  #[CLI\Command(name: 'neo:alchemist:rename', aliases: ['neoa-rename'])]
  #[CLI\Argument(name: 'old', description: 'The current neo_component id.')]
  #[CLI\Argument(name: 'new', description: 'The id to rename it to.')]
  #[CLI\Usage(name: 'drush neo:alchemist:rename list_search search_list', description: 'Rename the component and every placement of it.')]
  public function rename(string $old, string $new): int {
    $storage = $this->entityTypeManager->getStorage('neo_component');

    if ($old === $new) {
      $this->io()->error('The old and new ids are the same.');
      return self::EXIT_FAILURE;
    }
    if (!preg_match('/^[a-z0-9_]+$/', $new)) {
      $this->io()->error(sprintf('"%s" is not a valid component id (lowercase letters, numbers and underscores).', $new));
      return self::EXIT_FAILURE;
    }
    $entity = $storage->load($old);
    // ComponentUsage resolves host labels as it sweeps, and a label carrying
    // a neo_icon element throws from __toString with no render context open —
    // which is every Drush command. Give it one.
    $placements = $this->renderer->executeInRenderContext(
      new RenderContext(),
      fn (): int => $this->usage->getCounts()[$old]['total'] ?? 0
    );
    if (!$entity && !$placements) {
      $this->io()->error(sprintf('No component "%s", and nothing places it.', $old));
      return self::EXIT_FAILURE;
    }
    // A target that already exists is only ambiguous when the source exists
    // too — that would be a merge, not a rename. When the source is gone and
    // only stale placements are left, repointing them at the existing
    // component is precisely the repair a half-applied rename needs.
    if ($storage->load($new) && $entity) {
      $this->io()->error(sprintf('Both "%s" and "%s" exist. Renaming one onto the other would merge them; delete or rename the target first.', $old, $new));
      return self::EXIT_FAILURE;
    }

    $this->io()->text($entity
      ? sprintf('Renaming "%s" to "%s" (%d placement(s)).', $old, $new, $placements)
      : sprintf('"%s" does not exist; repointing its %d stale placement(s) at "%s".', $old, $placements, $new));
    if (!$this->io()->confirm('Continue?')) {
      return self::EXIT_FAILURE;
    }

    if ($entity) {
      // Written through the config factory, keeping the uuid, so a later
      // config import reads this as a rename rather than a delete plus a
      // create. Going through the entity API would mint a new uuid.
      $name = 'neo_alchemist.neo_component.';
      $configFactory = \Drupal::configFactory();
      $data = $configFactory->getEditable($name . $old)->getRawData();
      $data['id'] = $new;
      $configFactory->getEditable($name . $new)->setData($data)->save();
      $configFactory->getEditable($name . $old)->delete();
      $this->io()->text('Renamed the component entity.');
    }

    $rows = $this->renameInTrees($old, $new);
    $this->usage->reset();
    $this->cacheTagsInvalidator->invalidateTags([ComponentUsage::CACHE_TAG, 'rendered']);

    $this->io()->success(sprintf('Renamed "%s" to "%s" and rewrote %d tree row(s).', $old, $new, $rows));
    $this->io()->listing([
      'If the SDC directory was renamed too, point the component at it and run `drush cr`.',
      'Verify with: drush neo:alchemist:integrity',
      'Export when satisfied: drush config:export',
    ]);
    return self::EXIT_SUCCESS;
  }

  /**
   * Rewrites a component id in every stored tree.
   *
   * Targets the whole JSON key/value pair rather than the bare id, which could
   * otherwise appear inside an unrelated prop value.
   *
   * @return int
   *   The number of rows rewritten.
   */
  private function renameInTrees(string $old, string $new): int {
    $from = '"component":"' . $old . '"';
    $to = '"component":"' . $new . '"';
    $rows = 0;
    foreach ($this->treeColumns() as [$table, $treeColumn]) {
      $rows += $this->database->update($table)
        ->expression($treeColumn, "REPLACE({$treeColumn}, :from, :to)", [':from' => $from, ':to' => $to])
        ->condition($treeColumn, '%' . $this->database->escapeLike($from) . '%', 'LIKE')
        ->execute();
    }
    return $rows;
  }

}
