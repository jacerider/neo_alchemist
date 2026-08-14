<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * The locked and custom field-mode contracts.
 *
 * Mode is never stored — it is derived per call from allow_custom plus the
 * default layout's region_custom anchors, so these contracts hold the line
 * a config change silently crosses:
 * - locked (allow_custom off, no flagged regions): the default always
 *   renders and per-entity values are ignored on load;
 * - custom (allow_custom on): an empty entity renders the default, a saved
 *   entity tree replaces it wholesale.
 *
 * Locked mode also persists nothing at all, so nothing reading the column
 * directly mistakes a default snapshot for content — but only on insert, so
 * content authored while the field was hybrid survives a trip through locked.
 * Those two pull in opposite directions; both are pinned here.
 */
#[Group('neo_alchemist')]
class LockedAndCustomModeTest extends HybridFieldKernelTestBase {

  /**
   * A defaults layout with no region props anywhere: locked, not hybrid.
   */
  private function leafOnlyLayout(): array {
    return [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'default-leaf', 'component' => 'na_leaf'],
        ],
      ],
      'props' => [
        'default-leaf' => $this->leafProps('DEFAULT LEAF'),
      ],
    ];
  }

  /**
   * Reconfigures the field for a mode.
   */
  private function configureField(bool $allowCustom, array $defaults): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', $allowCustom);
    $field->setSetting('defaults', $defaults);
    $field->save();
    $this->resetFieldCaches('na_region_host');
  }

  /**
   * Locked mode ignores stored entity values on load.
   */
  public function testLockedIgnoresStoredValueOnLoad(): void {
    $this->configureField(FALSE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();
    $definition = $entity->get(static::FIELD_NAME)->getFieldDefinition();
    $this->assertFalse($definition->isHybrid(), 'Premise: no flagged regions, so the field is locked, not hybrid.');

    // Plant a rogue stored row directly — the API path is (correctly) unable
    // to write to a locked field. An INSERT, because a locked save persists
    // nothing at all; there is no row to update.
    $this->assertNull($this->rawStoredValue($entity), 'Premise: a locked save left no row to update.');
    $this->container->get('database')
      ->insert('entity_test__' . static::FIELD_NAME)
      ->fields([
        'bundle' => $entity->bundle(),
        'deleted' => 0,
        'entity_id' => $entity->id(),
        'revision_id' => $entity->getRevisionId() ?? $entity->id(),
        'langcode' => $entity->language()->getId(),
        'delta' => 0,
        static::FIELD_NAME . '_tree' => Json::encode([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'rogue', 'component' => 'na_leaf'],
          ],
        ]),
        static::FIELD_NAME . '_props' => Json::encode(['rogue' => $this->leafProps('ROGUE')]),
      ])
      ->execute();

    $tree = Json::decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree']);

    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $tree[ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The locked field renders the default; the stored row was ignored.',
    );
    $this->assertArrayNotHasKey('rogue', $tree, 'The rogue stored content is nowhere in the runtime value.');
  }

  /**
   * Locked mode persists nothing into entity rows.
   *
   * The constructor seeds the default into every entity-scope list, so without
   * the insert-time guard in preSave() that seed was written verbatim into
   * each entity's row. Such a row never rendered — the load path ignores it —
   * but it was indistinguishable from real usage to anything reading the
   * column directly, and it became a stale snapshot the moment the default
   * layout moved on: content usage reports counted components the layout had
   * already dropped.
   *
   * The field default is the only authority in locked mode, so the row stays
   * empty and the runtime value still renders the default.
   *
   * @see \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList::preSave()
   */
  public function testLockedModePersistsNothingIntoEntityRows(): void {
    $this->configureField(FALSE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();

    $this->assertNull($this->rawStoredValue($entity), 'A locked entity stores no row.');

    // The value still renders, and still renders after a re-save.
    $tree = Json::decode($entity->get(static::FIELD_NAME)->first()->getValue()['tree']);
    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $tree[ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The runtime value is the default layout, not the blanked storage value.',
    );

    $entity->set('name', 'Re-saved');
    $entity->save();
    $this->assertNull($this->rawStoredValue($this->reloadEntity($entity)), 'A locked update stores no row either.');
  }

  /**
   * Un-flagging the ONLY region does not destroy existing authored content.
   *
   * This is the hybrid → locked transition: with one flagged region,
   * un-ticking it empties the anchor set, so isHybrid() goes FALSE and the
   * field becomes plain locked. The hybrid merge/strip path — including the
   * orphan preservation that covers a PARTIAL un-flag — is never entered, so
   * something else has to keep the content alive.
   *
   * That something is core: SqlContentEntityStorage::saveToDedicatedTables()
   * skips a field entirely (no delete, no insert) when its value is unchanged
   * versus $entity->original. A locked list deterministically holds the
   * seeded default on both the entity and its original, so the field can
   * never look changed and the stored row is never rewritten.
   *
   * That makes the preservation real but INDIRECT — it rests on a core
   * optimization nothing in this module declares. This test is the guard: a
   * refactor that makes locked mode write anything on update (for instance
   * "tidying" preSave() to strip like the hybrid branch does) would rewrite
   * the row with a default snapshot and destroy every entity's authored
   * region content, silently. If that is ever done deliberately, the content
   * must be preserved some other way first.
   *
   * @see \Drupal\Tests\neo_alchemist\Kernel\HybridUnflaggedRegionTest
   */
  public function testUnflaggingTheOnlyRegionPreservesAuthoredContent(): void {
    // Start from the base fixture: hybrid, exactly one flagged region — the
    // same shape as node.project's field_full via project_full.
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-leaf', 'component' => 'na_leaf'],
    ], [
      'authored-leaf' => $this->leafProps('AUTHORED'),
    ]);

    $this->unflagRegion();

    $entity = $this->reloadEntity($entity);
    $definition = $entity->get(static::FIELD_NAME)->getFieldDefinition();
    $this->assertFalse($definition->isHybrid(), 'Premise: un-flagging the only region drops the field out of hybrid mode.');
    $this->assertFalse($definition->allowCustom(), 'Premise: the field is now plain locked.');

    // Ordinary saves unrelated to Alchemist: title edits, moderation, cron,
    // a migration. Each one is a chance to clobber the row.
    foreach ([1, 2, 3] as $cycle) {
      $entity = $this->reloadEntity($entity);
      $entity->set('name', 'Edited while locked ' . $cycle);
      $entity->save();

      $stored = $this->rawStoredValue($this->reloadEntity($entity));
      $this->assertSame(
        [['uuid' => 'authored-leaf', 'component' => 'na_leaf']],
        $stored['tree'][static::HOST_UUID]['body'] ?? NULL,
        sprintf('The authored region content survived locked save cycle %d.', $cycle),
      );
      $this->assertSame($this->leafProps('AUTHORED'), $stored['props']['authored-leaf'] ?? NULL, sprintf('The authored props survived locked save cycle %d.', $cycle));
    }

    // While locked the content is inert: the default renders.
    $merged = Json::decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree']);
    $this->assertSame(
      [['uuid' => static::SEED_UUID, 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['body'] ?? NULL,
      'While locked the default seed renders, not the stored content.',
    );

    // Re-flagging makes it live again — the round trip that matters.
    $this->flagRegion();
    $merged = Json::decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree']);
    $this->assertSame(
      [['uuid' => 'authored-leaf', 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['body'] ?? NULL,
      'Re-flagging the region brought the authored content back.',
    );
  }

  /**
   * Custom mode: an empty entity renders the default layout.
   */
  public function testCustomEmptyEntityRendersDefault(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();

    $tree = Json::decode($entity->get(static::FIELD_NAME)->first()->getValue()['tree']);

    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $tree[ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The constructor seed makes an empty custom entity render the default.',
    );
  }

  /**
   * Custom mode: a saved entity tree replaces the default wholesale.
   */
  public function testCustomSavedTreeReplacesDefaultWholesale(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'own-leaf', 'component' => 'na_leaf'],
          ],
        ],
        'props' => ['own-leaf' => $this->leafProps('OWN')],
      ],
    ]);
    $entity->save();

    $tree = Json::decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree']);

    $this->assertSame(
      [['uuid' => 'own-leaf', 'component' => 'na_leaf']],
      $tree[ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The entity tree replaced the default.',
    );
    $this->assertArrayNotHasKey('default-leaf', array_flip(array_column($tree[ComponentTreeStructure::ROOT_UUID], 'uuid')), 'No default content is merged in — custom is all-or-nothing.');
  }

}
