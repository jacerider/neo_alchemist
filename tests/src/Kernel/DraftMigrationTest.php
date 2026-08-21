<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * What neo_alchemist_update_11008() does to in-flight drafts in state.
 *
 * The whole-tree draft used to be keyed "neo_alchemist.<id>.<field>" in state.
 * That key space is gone — the draft lives behind SharedDraftStore under its
 * own "neo_alchemist_draft." prefix, with the entity type and langcode folded
 * in. The drafts already in state are unpublished editor work on a live site,
 * so the hook re-keys them rather than orphaning them: it moves each to its new
 * key, seeds the version and last editor a pre-metadata record lacks, and
 * leaves every
 * other kind of state — a filter's preview entity, disposable preview values —
 * untouched.
 *
 * These tests seed the OLD key by hand (the key function that built it is gone)
 * and assert the migrated draft is readable through the store at its new key.
 *
 * @see neo_alchemist_update_11008()
 * @see \Drupal\neo_alchemist\EditorState\SharedDraftStore::seedMigratedRecord()
 */
#[Group('neo_alchemist')]
class DraftMigrationTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('neo_alchemist', 'install');
  }

  /**
   * The pre-fix whole-tree draft key: "neo_alchemist.<id>.<field>", no uuid.
   */
  private function oldKey(EntityTest $entity): string {
    return 'neo_alchemist.' . $entity->id() . '.' . static::FIELD_NAME;
  }

  /**
   * Resolves the field item the store keys off, appending one if none is saved.
   *
   * A drafting entity may carry no saved field value, exactly as the hook finds
   * it, so the item is appended rather than assumed.
   */
  private function itemFor(EntityTest $entity): ComponentTreeItem {
    $list = $entity->get(static::FIELD_NAME);
    $item = $list->first() ?? $list->appendItem();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  /**
   * The shared draft store service.
   */
  private function store(): SharedDraftStore {
    return $this->container->get('neo_alchemist.shared_draft_store');
  }

  /**
   * An in-flight draft is re-keyed and readable through the store, well-formed.
   */
  public function testInFlightDraftIsRekeyedAndReadableThroughStore(): void {
    $entity = $this->createTestEntity();
    $content = ['tree' => '{"root":[{"uuid":"x","component":"na_leaf"}]}', 'props' => '{"x":{"status":true}}'];
    $this->container->get('state')->set($this->oldKey($entity), $content);

    $summary = neo_alchemist_update_11008();

    // The old key is gone, and the content reads back through the store.
    $this->assertNull($this->container->get('state')->get($this->oldKey($entity)), 'The old draft key was moved, not left behind.');
    $item = $this->itemFor($this->reloadEntity($entity));
    $this->assertSame($content, $this->store()->get($item), 'The migrated draft is readable through the store at its new key.');

    // The metadata a pre-migration record lacked is seeded: a real written
    // draft is version 1, its last editor is unknown rather than invented, and
    // it carries a last-modified stamp so presence can render something.
    $this->assertSame(1, $this->store()->version($item), 'The migrated draft is seeded at version 1.');
    $this->assertNull($this->store()->lastEditor($item), 'A pre-metadata draft has no known last editor.');
    $this->assertSame([], $this->store()->contributors($item), 'A migrated draft starts with no recorded contributors.');
    $this->assertIsInt($this->store()->lastModified($item), 'The migrated draft carries a last-modified stamp.');

    $this->assertStringContainsString('Re-keyed 1 in-flight draft', $summary);
  }

  /**
   * The new key folds in the entity type and langcode the old key omitted.
   *
   * That is the collision fix: the old key had neither, so drafts collided
   * across entity types sharing an id and translations shared one draft.
   */
  public function testNewKeyFoldsInEntityTypeAndLangcode(): void {
    $entity = $this->createTestEntity();
    $this->container->get('state')->set($this->oldKey($entity), ['tree' => '{}', 'props' => '{}']);

    neo_alchemist_update_11008();

    $langcode = $entity->language()->getId();
    $expected = 'neo_alchemist_draft.entity_test.' . $entity->id() . '.' . static::FIELD_NAME . '.' . $langcode;
    $keys = array_keys($this->container->get('keyvalue')->get('state')->getAll());
    $this->assertContains($expected, $keys, 'The new key folds in the entity type and langcode.');
    $this->assertNotContains($this->oldKey($entity), $keys, 'The old, type-and-langcode-less key is gone.');
  }

  /**
   * The count of migrated drafts is reported.
   */
  public function testMigrationReportsCount(): void {
    $a = $this->createTestEntity();
    $b = $this->createTestEntity();
    $state = $this->container->get('state');
    $state->set($this->oldKey($a), ['tree' => '{}', 'props' => '{}']);
    $state->set($this->oldKey($b), ['tree' => '{}', 'props' => '{}']);

    $summary = neo_alchemist_update_11008();

    $this->assertStringContainsString('Re-keyed 2 in-flight draft', $summary);
    $this->assertNull($state->get($this->oldKey($a)));
    $this->assertNull($state->get($this->oldKey($b)));
  }

  /**
   * Non-draft state — preview values, a filter's entity, a purge — is left be.
   *
   * Preview values are cache-backed disposable scratch and never in state, so
   * the hook never sees them; and any other neo_alchemist state key has a shape
   * the exact three-part draft match does not accept. Seeding one of each in
   * state and asserting they survive pins the hook's selectivity.
   */
  public function testNonDraftStateIsLeftUntouched(): void {
    $state = $this->container->get('state');
    $untouched = [
      // A preview value slice — different prefix (underscore, not dot).
      'neo_alchemist_preview.na_leaf.values.0' => ['props' => ['text' => 'x']],
      // A filter's preview entity — five parts.
      'neo_alchemist.5.filter.abcd.preview_entity' => 42,
      // An archived purge from an earlier update — two parts.
      'neo_alchemist.purged_locked_rows_11005' => ['some' => 'archive'],
      // Three parts, but not a real component tree field.
      'neo_alchemist.7.not_a_real_field' => ['tree' => '{}'],
    ];
    foreach ($untouched as $key => $value) {
      $state->set($key, $value);
    }

    neo_alchemist_update_11008();

    foreach ($untouched as $key => $value) {
      $this->assertSame($value, $state->get($key), sprintf('Non-draft state "%s" was left untouched.', $key));
    }
  }

  /**
   * A draft whose entity no longer exists is left in place, not dropped.
   *
   * The entity that would render it is gone, but the content is a content
   * creator's unpublished layout — kept under its old key for a maintainer
   * rather than silently discarded.
   */
  public function testDraftForDeletedEntityIsLeftInPlace(): void {
    $state = $this->container->get('state');
    $orphan = 'neo_alchemist.888888.' . static::FIELD_NAME;
    $state->set($orphan, ['tree' => '{}', 'props' => '{}']);

    $summary = neo_alchemist_update_11008();

    $this->assertSame(['tree' => '{}', 'props' => '{}'], $state->get($orphan), 'The orphaned draft is left under its old key.');
    $this->assertStringContainsString('Re-keyed 0 in-flight draft', $summary);
    $this->assertStringContainsString('entity no longer exists', $summary);
  }

}
