<?php

namespace Drupal\neo_alchemist\Plugin\Field;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\ComponentShapeQuery;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Defines an item list class for map fields.
 *
 * @method \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem first()
 * @method \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem get($index)
 * @method \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem offsetGet($offset)
 */
class NeoComponentTreeList extends FieldItemList {

  /**
   * The data definition.
   *
   * @var \Drupal\neo_alchemist\Entity\ComponentFieldConfig
   */
  protected $definition;

  /**
   * Flag indicating if the field list uses the default component values.
   *
   * @var bool
   */
  protected $isDefault = TRUE;

  /**
   * The list scope.
   *
   * @var string
   */
  protected $scope = 'entity';

  /**
   * Hybrid mode: orphaned stored sections/props preserved across saves.
   *
   * Orphans are stored sections whose custom-region anchor no longer exists in
   * the field default layout. They are never rendered or edited, but they are
   * carried through saves so a config revert that restores the anchor also
   * restores the content.
   *
   * @var array
   */
  protected array $hybridOrphans = [
    'tree' => [],
    'props' => [],
  ];

  /**
   * Stash of the runtime value while a reduced value is persisted.
   *
   * Both hybrid and locked scope replace the list value in ::preSave() with
   * what should actually hit the row — the entity-owned subset for hybrid,
   * nothing at all for a locked insert. ::postSave() puts the runtime value
   * back so references held across the save keep seeing what renders.
   *
   * @var array|null
   */
  protected ?array $preSaveRuntimeValue = NULL;

  /**
   * {@inheritDoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    if (!$definition instanceof ComponentFieldConfigInterface) {
      return;
    }
    if ((!$definition->allowCustom() || !$this->belongsToFieldConfig()) && $definition->hasComponentValues()) {
      // When the field value is empty and we are acting on an actual entity,
      // we need to populate the field with the default component values.
      $this->appendItem($definition->getComponentValues());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Entity\ComponentFieldConfig
   *   The field definition.
   */
  public function getFieldDefinition() {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Entity\Plugin\DataType\EntityAdapter
   *   The parent data type.
   */
  public function getParent() {
    return $this->parent;
  }

  /**
   * Get a query object for filtering components.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\ComponentShapeQuery
   *   A query object for the components in this list.
   */
  public function getQuery() {
    return new ComponentShapeQuery($this);
  }

  /**
   * {@inheritdoc}
   *
   * We have no UI for default values.
   */
  public function defaultValuesForm(array &$form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * {@inheritDoc}
   *
   * We override this method so that we can check if the actual field is empty.
   * This helps when determining if the field should be shown given that there
   * may be default values.
   */
  public function isEmpty() {
    $values = $this->getValue();
    if (!empty($values[0]['tree'])) {
      $tree = Json::decode($values[0]['tree']);
      if (empty($tree[ComponentTreeStructure::ROOT_UUID])) {
        return TRUE;
      }
    }
    return parent::isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // We do not set a default value so that the field defaults are used.
    return $this;
  }

  /**
   * Checks if the item is the default value.
   *
   * @return bool
   *   TRUE if the item is the default value, FALSE otherwise.
   */
  public function isDefault(): bool {
    return $this->isDefault;
  }

  /**
   * {@inheritDoc}
   */
  public function setValue($values, $notify = TRUE) {
    $definition = $this->getFieldDefinition();
    if (!$definition instanceof ComponentFieldConfigInterface) {
      return;
    }
    if (!$this->belongsToFieldConfig()) {
      if ($definition->isHybrid()) {
        // Hybrid mode: the field default layout stays authoritative for the
        // structure, but entities own the content of the customizable regions.
        $this->setHybridValue($values, $notify);
        return;
      }
      $this->isDefault = FALSE;
      if (!$definition->allowCustom()) {
        // If custom is not allowed. Do not allow the field to be set. Note that
        // the defaults have already been loaded.
        return;
      }
    }
    parent::setValue($values, $notify);
  }

  /**
   * Sets a hybrid-mode value by merging it into the field default layout.
   *
   * The incoming value may be a stored subset (only custom-region sections),
   * a full merged tree (in-session edits, drafts, legacy allow_custom copies)
   * or empty (reset). All forms normalize to the same merged result: the field
   * default structure with the entity-owned custom-region content overlaid.
   *
   * @param mixed $values
   *   The values, as passed to ::setValue().
   * @param bool $notify
   *   Whether to notify the parent.
   */
  protected function setHybridValue($values, $notify = TRUE): void {
    $definition = $this->getFieldDefinition();
    $itemValue = NULL;
    if (is_array($values)) {
      if (array_key_exists(0, $values)) {
        $itemValue = $values[0];
      }
      elseif (array_key_exists('tree', $values) || array_key_exists('props', $values)) {
        $itemValue = $values;
      }
    }
    [$storedTree, $storedProps] = static::decodeHybridItemValue(is_array($itemValue) ? $itemValue : []);
    $rootTuples = $storedTree[ComponentTreeStructure::ROOT_UUID] ?? NULL;
    unset($storedTree[ComponentTreeStructure::ROOT_UUID]);
    if (empty($storedTree)) {
      // Nothing beyond the root: reset to the pure field default.
      parent::setValue(NULL, $notify);
      if ($definition->hasComponentValues()) {
        $this->appendItem($definition->getComponentValues());
      }
      $this->hybridOrphans = ['tree' => [], 'props' => []];
      $this->isDefault = TRUE;
      return;
    }
    if (empty($rootTuples)) {
      // An empty root section marks an authoritative storage subset
      // (::extractHybridStorageValue() always writes one): it replaces
      // everything derived from the previous stored value, including stashed
      // orphans — letting those accumulate across loads would re-emit stale
      // entries on the next save and resurrect deleted content. A populated
      // root marks an in-session merged tree, which cannot express orphans
      // at all, so the ones stashed at load time are deliberately KEPT —
      // resetting here would drop them on every editor commit. The draft
      // path (::composeHybridItemValue()) keeps them for the same reason.
      $this->hybridOrphans = ['tree' => [], 'props' => []];
    }
    $this->isDefault = FALSE;
    parent::setValue([0 => $this->composeHybridValue($storedTree, $storedProps)], $notify);
  }

  /**
   * Composes the merged hybrid value for a decoded stored tree/props pair.
   *
   * Also stashes any orphaned sections (content whose custom-region anchor no
   * longer exists in the default layout) so they survive the next save.
   *
   * @param array $storedTree
   *   The decoded stored tree, without the root key.
   * @param array $storedProps
   *   The decoded stored props.
   *
   * @return array
   *   The merged item value with 'tree' and 'props' JSON strings.
   */
  protected function composeHybridValue(array $storedTree, array $storedProps): array {
    $definition = $this->getFieldDefinition();
    $anchors = $definition->getCustomRegions();
    $defaults = $definition->getComponentValues();
    $defaultTree = $defaults['tree'] ?? [ComponentTreeStructure::ROOT_UUID => []];
    $defaultProps = $defaults['props'] ?? [];

    $mergedTree = $defaultTree;
    $mergedProps = $defaultProps;
    $ownedUuids = static::getSectionClosureUuids($storedTree, $anchors);
    $ownedFlip = array_flip($ownedUuids);

    foreach ($anchors as $ownerUuid => $anchor) {
      if (!array_key_exists($ownerUuid, $storedTree)) {
        // The anchor is not present in the stored value — it was added to the
        // default layout after this entity was last saved. Its default seed
        // children apply.
        continue;
      }
      foreach ($anchor['slots'] as $slotId) {
        // The stored value is authoritative for this flagged slot: drop the
        // default seed closure before overlaying.
        $seedTuples = $defaultTree[$ownerUuid][$slotId] ?? [];
        foreach (static::expandTupleClosure($defaultTree, array_column($seedTuples, 'uuid')) as $seedUuid) {
          unset($mergedTree[$seedUuid], $mergedProps[$seedUuid]);
        }
        $storedTuples = array_values($storedTree[$ownerUuid][$slotId] ?? []);
        if ($storedTuples) {
          $mergedTree[$ownerUuid][$slotId] = $storedTuples;
        }
        else {
          unset($mergedTree[$ownerUuid][$slotId]);
          if (empty($mergedTree[$ownerUuid])) {
            unset($mergedTree[$ownerUuid]);
          }
        }
      }
    }

    // Overlay the entity-owned descendant sections and props.
    foreach ($ownedUuids as $uuid) {
      if (isset($storedTree[$uuid])) {
        $mergedTree[$uuid] = $storedTree[$uuid];
      }
      if (array_key_exists($uuid, $storedProps)) {
        $mergedProps[$uuid] = $storedProps[$uuid];
      }
    }

    // Stash orphaned content, slot by slot: stored slots that the current
    // anchors no longer own and that the default layout does not carry
    // identically. This covers both a vanished anchor (the key is no longer
    // in the default tree) and an un-flagged one (the key is still present
    // but region_custom was removed) — in both cases the entity's authored
    // region content must survive the next save so a config revert or
    // re-flag restores it. Orphans are kept out of the merged runtime value
    // but re-emitted on save.
    //
    // The default-layout comparison is what distinguishes "a full merged
    // tree passed through" (non-anchor default sections arrive verbatim and
    // must NOT be stashed) from genuinely entity-authored content: hybrid
    // locks inherited instances, so a non-anchor section can only diverge
    // from the default when it was authored inside a formerly-flagged
    // region. Loose == tolerates key-order differences while catching any
    // content divergence.
    $defaultFlip = array_flip(static::getTreeUuids($defaultTree));
    foreach ($storedTree as $key => $section) {
      if (isset($ownedFlip[$key])) {
        continue;
      }
      foreach ((array) $section as $slotId => $tuples) {
        if (isset($anchors[$key]) && in_array($slotId, $anchors[$key]['slots'], TRUE)) {
          // Currently flagged: the anchors merge loop above owns this slot.
          continue;
        }
        if (isset($defaultFlip[$key]) && ($defaultTree[$key][$slotId] ?? []) == $tuples) {
          // Identical to the default layout — nothing entity-authored here.
          continue;
        }
        $this->hybridOrphans['tree'][$key][$slotId] = $tuples;
        foreach ((array) $tuples as $tuple) {
          $uuid = $tuple['uuid'] ?? NULL;
          if ($uuid && array_key_exists($uuid, $storedProps)) {
            $this->hybridOrphans['props'][$uuid] = $storedProps[$uuid];
          }
        }
      }
    }

    // Encode here: the props JSON must always be an object ('{}' when empty),
    // which a plain empty PHP array would not produce.
    // @see \Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues::setValue()
    return [
      'tree' => Json::encode($mergedTree),
      'props' => $mergedProps ? Json::encode($mergedProps) : '{}',
    ];
  }

  /**
   * Normalizes a hybrid item value (e.g. a stashed draft) to a merged value.
   *
   * Re-composes the value against the current field default layout so that
   * default-structure changes made since the value was stashed are reflected.
   *
   * @param array $itemValue
   *   An item value with 'tree'/'props' keys (JSON strings or arrays).
   *
   * @return array
   *   The merged item value with 'tree' and 'props' JSON strings.
   */
  public function composeHybridItemValue(array $itemValue): array {
    [$storedTree, $storedProps] = static::decodeHybridItemValue($itemValue);
    unset($storedTree[ComponentTreeStructure::ROOT_UUID]);
    $this->isDefault = FALSE;
    return $this->composeHybridValue($storedTree, $storedProps);
  }

  /**
   * Checks if this list operates in hybrid mode on an actual entity.
   *
   * @return bool
   *   TRUE when hybrid and entity scope, FALSE otherwise.
   */
  public function isHybridScope(): bool {
    $definition = $this->getFieldDefinition();
    return $definition instanceof ComponentFieldConfigInterface
      && !$this->belongsToFieldConfig()
      && $definition->isHybrid();
  }

  /**
   * Checks if this list operates in locked mode on an actual entity.
   *
   * Locked is the absence of both other modes: the field default layout is
   * wholly authoritative and ::setValue() discards anything the row held.
   *
   * @return bool
   *   TRUE when locked and entity scope, FALSE otherwise.
   */
  public function isLockedScope(): bool {
    $definition = $this->getFieldDefinition();
    return $definition instanceof ComponentFieldConfigInterface
      && !$this->belongsToFieldConfig()
      && !$definition->allowCustom()
      && !$definition->isHybrid();
  }

  /**
   * Extracts the entity-owned storage subset from the current merged value.
   *
   * Once an entity is customized, the stored value is authoritative for every
   * custom region: each flagged slot is always written (an empty slot means
   * "explicitly empty"), so an anchor missing from storage can only mean it
   * was added to the default layout after this entity was last saved.
   *
   * @return array
   *   An item value with 'tree' and 'props' arrays containing only the
   *   entity-owned subset (plus preserved orphans).
   */
  protected function extractHybridStorageValue(): array {
    $definition = $this->getFieldDefinition();
    $anchors = $definition->getCustomRegions();
    $item = $this->first();
    [$tree, $props] = static::decodeHybridItemValue($item ? $item->getValue() : []);

    $storageTree = [ComponentTreeStructure::ROOT_UUID => []];
    $storageProps = [];
    $ownedUuids = static::getSectionClosureUuids($tree, $anchors);
    foreach ($anchors as $ownerUuid => $anchor) {
      foreach ($anchor['slots'] as $slotId) {
        $storageTree[$ownerUuid][$slotId] = array_values($tree[$ownerUuid][$slotId] ?? []);
      }
    }
    foreach ($ownedUuids as $uuid) {
      if (isset($tree[$uuid])) {
        $storageTree[$uuid] = $tree[$uuid];
      }
      if (array_key_exists($uuid, $props)) {
        $storageProps[$uuid] = $props[$uuid];
      }
    }
    // Re-emit preserved orphans, slot by slot. A partially-anchored owner
    // (one region still flagged, another un-flagged) already has a storage
    // section for its live slots, so a whole-section guard would never fire
    // and the orphaned slot would be lost on save.
    foreach ($this->hybridOrphans['tree'] as $key => $section) {
      foreach ((array) $section as $slotId => $tuples) {
        if (!isset($storageTree[$key][$slotId])) {
          $storageTree[$key][$slotId] = $tuples;
        }
      }
    }
    foreach ($this->hybridOrphans['props'] as $uuid => $value) {
      if (!array_key_exists($uuid, $storageProps)) {
        $storageProps[$uuid] = $value;
      }
    }
    // Guarantee tree/props parity: every tree INSTANCE needs a props entry.
    // Instances are tuple uuids — a container inside a custom region has its
    // own section AND is a tuple, and it is exactly the case that must be
    // backfilled. Anchor owners appear only as section keys (the subset root
    // is empty) and are not instances, so they get no entry.
    // @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::preSave()
    foreach (static::getTreeTupleUuids($storageTree) as $uuid) {
      if (!array_key_exists($uuid, $storageProps)) {
        $storageProps[$uuid] = [];
      }
    }
    return [
      'tree' => $storageTree,
      'props' => $storageProps,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    if ($this->isHybridScope()) {
      $this->preSaveRuntimeValue = $this->getValue();
      if ($this->isDefault()) {
        // Nothing has been customized: persist nothing so the field default
        // remains fully authoritative. This tree form is what isEmpty()
        // treats as empty, so the item is filtered out before storage write.
        foreach ($this->list as $item) {
          $item->setValue([
            'tree' => Json::encode([]),
            'props' => '{}',
          ], FALSE);
        }
      }
      else {
        $storage = $this->extractHybridStorageValue();
        if ($item = $this->first()) {
          $item->setValue([
            'tree' => Json::encode($storage['tree']),
            'props' => $storage['props'] ? Json::encode($storage['props']) : '{}',
          ], FALSE);
        }
      }
    }
    elseif ($this->isLockedScope() && $this->getEntity()->isNew()) {
      // Locked mode: the constructor seeds the default layout into every
      // entity-scope list, and without this the seed is written verbatim into
      // the entity's row on insert. That row is dead weight — ::setValue()
      // discards it on every subsequent load — but it is indistinguishable
      // from real usage to anything reading the column directly, and it
      // becomes a stale snapshot the moment the default layout moves on.
      // Persist nothing instead; the field default stays authoritative.
      //
      // ONLY on insert. On update the row may hold content authored while the
      // field was hybrid, kept alive purely because core skips unchanged
      // fields in saveToDedicatedTables(). That content is invisible here (the
      // load path already dropped it, and $entity->original holds the same
      // seeded default), so blanking on update would destroy it silently.
      // @see \Drupal\Tests\neo_alchemist\Kernel\LockedAndCustomModeTest::testUnflaggingTheOnlyRegionPreservesAuthoredContent
      $this->preSaveRuntimeValue = $this->getValue();
      foreach ($this->list as $item) {
        $item->setValue([
          'tree' => Json::encode([]),
          'props' => '{}',
        ], FALSE);
      }
    }
    parent::preSave();
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    $result = parent::postSave($update);
    if ($this->preSaveRuntimeValue !== NULL) {
      // Restore the runtime value that ::preSave() replaced with the reduced
      // storage value. Restore in place so references held across the save
      // keep seeing what renders.
      foreach ($this->preSaveRuntimeValue as $delta => $value) {
        if (isset($this->list[$delta])) {
          $this->list[$delta]->setValue($value, FALSE);
        }
        else {
          $this->appendItem($value);
        }
      }
      $this->preSaveRuntimeValue = NULL;
    }
    return $result;
  }

  /**
   * Decodes an item value into tree and props arrays.
   *
   * @param array $itemValue
   *   An item value with 'tree'/'props' keys (JSON strings or arrays).
   *
   * @return array
   *   A tuple of the decoded tree and props arrays.
   */
  protected static function decodeHybridItemValue(array $itemValue): array {
    $tree = $itemValue['tree'] ?? NULL;
    $props = $itemValue['props'] ?? NULL;
    return [
      is_string($tree) ? (Json::decode($tree) ?: []) : (is_array($tree) ? $tree : []),
      is_string($props) ? (Json::decode($props) ?: []) : (is_array($props) ? $props : []),
    ];
  }

  /**
   * Gets the closure of component UUIDs living inside custom-region anchors.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $anchors
   *   Custom-region anchors, as returned by
   *   \Drupal\neo_alchemist\ComponentFieldConfigInterface::getCustomRegions().
   *
   * @return string[]
   *   All component instance UUIDs placed inside the flagged slots, including
   *   nested descendants. Anchor owners themselves are not included.
   */
  public static function getSectionClosureUuids(array $tree, array $anchors): array {
    $queue = [];
    foreach ($anchors as $ownerUuid => $anchor) {
      foreach ($anchor['slots'] as $slotId) {
        foreach ((array) ($tree[$ownerUuid][$slotId] ?? []) as $tuple) {
          if (!empty($tuple['uuid'])) {
            $queue[] = $tuple['uuid'];
          }
        }
      }
    }
    return static::expandTupleClosure($tree, $queue);
  }

  /**
   * Expands a list of UUIDs with all of their tree descendants.
   *
   * @param array $tree
   *   A decoded component tree.
   * @param array $uuids
   *   The seed UUIDs.
   *
   * @return string[]
   *   The seed UUIDs plus every descendant found in the tree.
   */
  protected static function expandTupleClosure(array $tree, array $uuids): array {
    $found = [];
    while ($uuids) {
      $uuid = array_shift($uuids);
      if (!is_string($uuid) || isset($found[$uuid])) {
        continue;
      }
      $found[$uuid] = TRUE;
      foreach ((array) ($tree[$uuid] ?? []) as $slotTuples) {
        foreach ((array) $slotTuples as $tuple) {
          if (!empty($tuple['uuid'])) {
            $uuids[] = $tuple['uuid'];
          }
        }
      }
    }
    return array_keys($found);
  }

  /**
   * Gets every UUID referenced by a decoded component tree.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   All section keys and tuple UUIDs, excluding the root key.
   */
  protected static function getTreeUuids(array $tree): array {
    $uuids = [];
    foreach ($tree as $key => $section) {
      if ($key !== ComponentTreeStructure::ROOT_UUID) {
        $uuids[$key] = TRUE;
      }
      $lists = $key === ComponentTreeStructure::ROOT_UUID ? [$section] : array_values((array) $section);
      foreach ($lists as $tuples) {
        foreach ((array) $tuples as $tuple) {
          if (!empty($tuple['uuid'])) {
            $uuids[$tuple['uuid']] = TRUE;
          }
        }
      }
    }
    return array_keys($uuids);
  }

  /**
   * Gets every tuple UUID referenced by a decoded component tree.
   *
   * Unlike ::getTreeUuids(), section-only keys are excluded: in a hybrid
   * storage subset an anchor owner is a section key without being a
   * component instance of the subset itself.
   *
   * @param array $tree
   *   A decoded component tree.
   *
   * @return string[]
   *   The tuple UUIDs.
   */
  protected static function getTreeTupleUuids(array $tree): array {
    $uuids = [];
    foreach ($tree as $key => $section) {
      $lists = $key === ComponentTreeStructure::ROOT_UUID ? [$section] : array_values((array) $section);
      foreach ($lists as $tuples) {
        foreach ((array) $tuples as $tuple) {
          if (!empty($tuple['uuid'])) {
            $uuids[$tuple['uuid']] = TRUE;
          }
        }
      }
    }
    return array_keys($uuids);
  }

  /**
   * Get the scope of the field item list.
   *
   * @return string
   *   The scope of the field item list, e.g., 'entity', 'config'.
   */
  public function getScope(): string {
    return $this->scope;
  }

  /**
   * Set scope as field config.
   *
   * @return $this
   */
  public function setAsFieldConfig(): self {
    $this->scope = 'config';
    return $this;
  }

  /**
   * Checks if the item belongs to a field config.
   *
   * @return bool
   *   TRUE if the item belongs to an actual entity, FALSE otherwise.
   */
  public function belongsToFieldConfig(): bool {
    return $this->getScope() === 'config';
  }

}
