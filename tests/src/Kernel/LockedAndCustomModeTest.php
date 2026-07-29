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
 * One method is deliberately a CHARACTERIZATION of a flagged-but-unfixed
 * behavior (locked mode persisting a default snapshot into entity rows) —
 * it documents, not endorses; see the method docblock.
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

    // Plant a rogue stored row directly — the API path is (correctly)
    // unable to write to a locked field. An UPDATE, because the locked save
    // already persisted a row (the snapshot behavior characterized below).
    $this->container->get('database')
      ->update('entity_test__' . static::FIELD_NAME)
      ->fields([
        static::FIELD_NAME . '_tree' => Json::encode([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'rogue', 'component' => 'na_leaf'],
          ],
        ]),
        static::FIELD_NAME . '_props' => Json::encode(['rogue' => $this->leafProps('ROGUE')]),
      ])
      ->condition('entity_id', $entity->id())
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
   * CHARACTERIZATION: locked mode persists a default snapshot on save.
   *
   * The constructor always seeds the default and preSave() only strips in
   * hybrid scope, so saving an entity with a locked field writes a frozen
   * copy of the default layout into its row. Invisible while the field stays
   * locked (the load path ignores it) — but it becomes a stale snapshot that
   * starts rendering the moment allow_custom is flipped on, and a
   * fully-customized hybrid value the moment a region_custom flag appears.
   * Documented here, deliberately NOT endorsed; see TESTING.md's residual
   * list. If this fails after a fix that makes locked fields persist
   * nothing, update it to pin the new (better) behavior.
   */
  public function testLockedModeCurrentlyPersistsDefaultSnapshotIntoEntityRows(): void {
    $this->configureField(FALSE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();

    $stored = $this->rawStoredValue($entity);

    $this->assertNotNull($stored, 'A locked entity currently stores a row.');
    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $stored['tree'][ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The row is a frozen copy of the default layout.',
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
