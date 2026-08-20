<?php

namespace Drupal\neo_alchemist\Plugin\Field;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeQuery;
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
    [$storedTree, $storedProps] = ComponentTreeStructure::decodeValue(is_array($itemValue) ? $itemValue : []);
    $isStorageSubset = ComponentTreeStructure::isStorageSubset($storedTree);
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
    if ($isStorageSubset) {
      // An authoritative storage subset (::extractHybridStorageValue() always
      // writes one) replaces everything derived from the previous stored
      // value, including stashed orphans — letting those accumulate across
      // loads would re-emit stale entries on the next save and resurrect
      // deleted content. A populated root marks an in-session merged tree,
      // which cannot express orphans at all, so the ones stashed at load time
      // are deliberately KEPT — resetting here would drop them on every editor
      // commit. The draft path (::composeHybridItemValue()) keeps them for the
      // same reason.
      $this->hybridOrphans = ['tree' => [], 'props' => []];
    }
    $this->isDefault = FALSE;
    parent::setValue([0 => $this->composeHybridValue($storedTree, $storedProps)], $notify);
  }

  /**
   * Composes the merged hybrid value for a decoded stored tree/props pair.
   *
   * The merge itself is tree algebra and lives on the seam. What stays here is
   * the field-lifecycle half: where the inputs come from (the field config's
   * anchors and default layout), stashing the orphans the merge detected so
   * they survive the next save, and the storage encoding.
   *
   * @param array $storedTree
   *   The decoded stored tree, without the root key.
   * @param array $storedProps
   *   The decoded stored props.
   *
   * @return array
   *   The merged item value with 'tree' and 'props' JSON strings.
   *
   * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::composeHybrid()
   */
  protected function composeHybridValue(array $storedTree, array $storedProps): array {
    $definition = $this->getFieldDefinition();
    $merged = ComponentTreeStructure::composeHybrid(
      $definition->getComponentValues(),
      $storedTree,
      $storedProps,
      $definition->getCustomRegions(),
    );

    // Accumulate rather than replace: a value arriving as a full merged tree
    // cannot express orphans at all, so the ones stashed by an earlier compose
    // in the same request must survive this one.
    foreach ($merged['orphans']['tree'] as $key => $section) {
      foreach ($section as $slotId => $tuples) {
        $this->hybridOrphans['tree'][$key][$slotId] = $tuples;
      }
    }
    foreach ($merged['orphans']['props'] as $uuid => $value) {
      $this->hybridOrphans['props'][$uuid] = $value;
    }

    // Encode here: the props JSON must always be an object ('{}' when empty),
    // which a plain empty PHP array would not produce.
    // @see \Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues::setValue()
    return [
      'tree' => Json::encode($merged['tree']),
      'props' => $merged['props'] ? Json::encode($merged['props']) : '{}',
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
    [$storedTree, $storedProps] = ComponentTreeStructure::decodeValue($itemValue);
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
   * The extraction itself is tree algebra and lives on the seam, parity
   * included. What stays here is reading the inputs: the item's merged value,
   * the field config's anchors, and the orphan stash this list carries.
   *
   * @return array
   *   An item value with 'tree' and 'props' arrays containing only the
   *   entity-owned subset (plus preserved orphans).
   *
   * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::extractHybridStorage()
   */
  protected function extractHybridStorageValue(): array {
    $item = $this->first();
    [$tree, $props] = ComponentTreeStructure::decodeValue($item ? $item->getValue() : []);
    return ComponentTreeStructure::extractHybridStorage(
      $tree,
      $props,
      $this->getFieldDefinition()->getCustomRegions(),
      $this->hybridOrphans,
    );
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
