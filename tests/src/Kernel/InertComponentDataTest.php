<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\InertComponentData;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * Finding and purging layout data that nothing renders.
 *
 * The case this exists for: a field allows customization, editors customize
 * entities, a site builder later turns customization off. Nothing in the
 * module reacts to that setting changing, so every authored tree stops
 * rendering and stays in the database — invisible, because the usage report
 * (correctly) does not count it as usage.
 *
 * The line that matters most here is which dead rows may be deleted. A locked
 * field's row with a POPULATED root is a leftover full tree: purgeable. A row
 * with an EMPTY root is a hybrid storage subset — region content that
 * re-flagging the region legitimately restores — and must survive.
 *
 * @see \Drupal\neo_alchemist\InertComponentData
 */
#[Group('neo_alchemist')]
class InertComponentDataTest extends HybridFieldKernelTestBase {

  /**
   * The service under test.
   */
  private InertComponentData $inertData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->inertData = $this->container->get('neo_alchemist.inert_component_data');
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
    // ::scan() reads field configs from storage, not from definitions.
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
  }

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
      'props' => ['default-leaf' => $this->leafProps('DEFAULT LEAF')],
    ];
  }

  /**
   * Customizes an entity's whole tree, which only custom mode permits.
   */
  private function customizeEntity(): object {
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'authored', 'component' => 'na_two_region'],
          ],
        ],
        'props' => ['authored' => []],
      ],
    ]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * Counts inert entities for the fixture field.
   */
  private function countInert(): int {
    return $this->inertData->countFor('entity_test', 'entity_test', static::FIELD_NAME);
  }

  /**
   * Purges the fixture field.
   */
  private function purge(): int {
    return $this->inertData->purge('entity_test', 'entity_test', static::FIELD_NAME);
  }

  /**
   * Turning customization off strands the authored layouts, and we find them.
   */
  public function testCustomToLockedStrandsAuthoredLayouts(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
    $entity = $this->customizeEntity();
    $this->assertSame(0, $this->countInert(), 'While customization is on, the stored tree is live, not inert.');
    $this->assertSame([], $this->inertData->scan(), 'A customizable field reports nothing inert.');

    $this->configureField(FALSE, $this->leafOnlyLayout());

    $this->assertSame(1, $this->countInert(), 'Once locked, the stored tree is inert.');
    $scan = $this->inertData->scan();
    $this->assertCount(1, $scan, 'The field is reported once, not once per entity.');
    $this->assertContains('na_two_region', $scan[0]['components'], 'The scan names the components stranded in the row.');

    // Still stored, still not rendered — the whole point of "inert".
    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame(
      [['uuid' => 'authored', 'component' => 'na_two_region']],
      $stored['tree'][ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The authored tree is untouched in the database.',
    );
    $rendered = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $rendered[ComponentTreeStructure::ROOT_UUID] ?? NULL,
      'The default layout renders, not the stranded tree.',
    );
  }

  /**
   * Purging deletes the rows and archives them first.
   */
  public function testPurgeDeletesAndArchives(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
    $entity = $this->customizeEntity();
    $this->configureField(FALSE, $this->leafOnlyLayout());

    $this->assertSame(1, $this->purge(), 'The purge reports the entity it cleared.');

    $this->assertNull($this->rawStoredValue($this->reloadEntity($entity)), 'The row is gone.');
    $this->assertSame(0, $this->countInert(), 'Nothing inert is left.');
    $this->assertSame([], $this->inertData->scan());

    $archive = $this->container->get('state')->get(InertComponentData::STATE_KEY, []);
    $this->assertArrayHasKey('entity_test.entity_test.' . static::FIELD_NAME, $archive, 'The purge is reversible from state.');
    $this->assertNotEmpty($archive['entity_test.entity_test.' . static::FIELD_NAME]);

    // The field still renders its default; purging touches storage only.
    $rendered = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'default-leaf', 'component' => 'na_leaf']],
      $rendered[ComponentTreeStructure::ROOT_UUID] ?? NULL,
    );
  }

  /**
   * Hybrid-era region content is never inert and never purged.
   *
   * Un-flagging the last region drops the field to plain locked, so its rows
   * look dead — but re-flagging brings the content back, so deleting it would
   * be silent data loss. The empty-root rule is what protects it.
   *
   * @see \Drupal\Tests\neo_alchemist\Kernel\LockedAndCustomModeTest::testUnflaggingTheOnlyRegionPreservesAuthoredContent
   */
  public function testHybridEraContentIsNeverPurged(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-leaf', 'component' => 'na_leaf'],
    ], [
      'authored-leaf' => $this->leafProps('AUTHORED'),
    ]);

    $this->unflagRegion();
    $definition = $this->reloadEntity($entity)->get(static::FIELD_NAME)->getFieldDefinition();
    $this->assertFalse($definition->isHybrid(), 'Premise: the field is now plain locked.');
    $this->assertFalse($definition->allowCustom(), 'Premise: customization is off.');

    $this->assertSame(0, $this->countInert(), 'Hybrid-era content is not counted as inert.');
    $this->assertSame([], $this->inertData->scan(), 'Hybrid-era content is not reported as inert.');
    $this->assertSame(0, $this->purge(), 'A purge finds nothing to do.');

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame(
      [['uuid' => 'authored-leaf', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['body'] ?? NULL,
      'The authored region content survived the purge.',
    );

    // And it is still recoverable, which is why it had to survive.
    $this->flagRegion();
    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'authored-leaf', 'component' => 'na_leaf']],
      $merged[static::HOST_UUID]['body'] ?? NULL,
      'Re-flagging the region brought the preserved content back.',
    );
  }

  /**
   * A hybrid field reports nothing inert while it is still hybrid.
   */
  public function testHybridFieldReportsNothingInert(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-leaf', 'component' => 'na_leaf'],
    ], [
      'authored-leaf' => $this->leafProps('AUTHORED'),
    ]);

    $this->assertSame(0, $this->countInert());
    $this->assertSame([], $this->inertData->scan());
  }

}
