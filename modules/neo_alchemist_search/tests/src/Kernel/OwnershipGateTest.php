<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Kernel;

use Drupal\Tests\neo_alchemist\Kernel\HybridFieldKernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;
use Drupal\neo_alchemist_search\ComponentTextBuffer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers which component instances count as the entity's own.
 *
 * This is the correctness property the whole authored half rests on. A field
 * default layout is shared by every entity of a bundle, so extracting its text
 * would give hundreds of entities an identical block of boilerplate and bury
 * whatever actually distinguishes them. Every assertion here is really the
 * same one: inherited text stays out.
 */
#[Group('neo_alchemist_search')]
final class OwnershipGateTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
    'neo_alchemist_search',
  ];

  /**
   * A hybrid field yields the entity's own region content, not the layout's.
   */
  public function testHybridYieldsOnlyEntityOwnedContent(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $this->authorRegionContent(
      $entity,
      [['uuid' => 'own-1', 'component' => 'na_leaf']],
      ['own-1' => $this->leafProps('AUTHORED TEXT')],
    );
    $entity = $this->reloadEntity($entity);

    $texts = $this->extract($entity);
    $this->assertContains('AUTHORED TEXT', $texts);
    // Both live in the shared default layout, so neither says anything about
    // this entity in particular.
    $this->assertNotContains('DEFAULT HEADING', $texts);
    $this->assertNotContains('SEED TEXT', $texts);
  }

  /**
   * A hybrid field whose regions were left alone yields nothing.
   */
  public function testHybridWithoutOwnContentYieldsNothing(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $this->assertSame([], $this->extract($entity));
  }

  /**
   * A locked field yields nothing, even with a row left behind in storage.
   *
   * Demoting a hybrid field to locked leaves whatever the entity had already
   * written sitting in the table. NeoComponentTreeList::setValue() discards it
   * on load, so it never renders — and it must not be indexed either.
   */
  public function testLockedYieldsNothingDespiteStoredRow(): void {
    $entity = $this->createTestEntity();
    $this->authorRegionContent(
      $entity,
      [['uuid' => 'own-1', 'component' => 'na_leaf']],
      ['own-1' => $this->leafProps('STRANDED TEXT')],
    );

    // Demote the field: the stored row survives, its authority does not.
    $this->unflagRegion();
    $this->resetFieldCaches('na_region_host');
    $entity = $this->reloadEntity($entity);

    $list = $entity->get(static::FIELD_NAME);
    $this->assertInstanceOf(NeoComponentTreeList::class, $list);
    $this->assertTrue($list->isLockedScope(), 'Premise: the field is now locked.');
    $this->assertNotNull($this->rawStoredValue($entity), 'Premise: the row is still in storage.');

    $texts = $this->extract($entity);
    $this->assertNotContains('STRANDED TEXT', $texts);
    $this->assertSame([], $texts);
  }

  /**
   * A free-form field still serving the seeded default yields nothing.
   *
   * A free-form list seeds itself with the default layout so the entity has
   * something to render before anyone has touched it. Until a row is actually
   * written, that text belongs to the bundle rather than the entity, and
   * isDefault() is the only thing that says so — the tree looks identical
   * either way.
   *
   * Saving is what transfers ownership: the seeded copy is persisted as the
   * entity's own row, and from then on an editor can change it, so it counts
   * as theirs. Hence the unsaved entity here.
   */
  public function testFreeFormSeededDefaultYieldsNothing(): void {
    $this->setAllowCustom(TRUE);
    $entity = EntityTest::create([]);

    $list = $entity->get(static::FIELD_NAME);
    $this->assertInstanceOf(NeoComponentTreeList::class, $list);
    $this->assertTrue($list->isDefault(), 'Premise: the list is serving the seeded default layout.');
    $this->assertNotSame([], $list->first()?->get('tree')?->getComponents() ?? [], 'Premise: the seeded tree is not empty.');

    $this->assertSame([], $this->extract($entity));
  }

  /**
   * A free-form field with a row of its own yields all of it.
   */
  public function testFreeFormYieldsWholeStoredTree(): void {
    $this->setAllowCustom(TRUE);
    $entity = $this->createTestEntity();
    $this->authorRegionContent(
      $entity,
      [['uuid' => 'own-1', 'component' => 'na_leaf']],
      ['own-1' => $this->leafProps('FREE FORM TEXT')],
    );
    $entity = $this->reloadEntity($entity);

    $texts = $this->extract($entity);
    $this->assertContains('FREE FORM TEXT', $texts);
    // With no custom region to scope to, the entity owns the row outright —
    // including the parts that started life as the default layout.
    $this->assertContains('DEFAULT HEADING', $texts);
  }

  /**
   * An unpublished instance contributes nothing.
   */
  public function testUnpublishedInstanceIsSkipped(): void {
    $entity = $this->createTestEntity();
    $props = $this->leafProps('HIDDEN TEXT');
    $props['status'] = FALSE;
    $this->authorRegionContent(
      $entity,
      [['uuid' => 'own-1', 'component' => 'na_leaf']],
      ['own-1' => $props],
    );
    $entity = $this->reloadEntity($entity);

    $this->assertNotContains('HIDDEN TEXT', $this->extract($entity));
  }

  /**
   * Flips the fixture field between locked/hybrid and free-form.
   */
  private function setAllowCustom(bool $allow): void {
    $field = $this->container->get('entity_type.manager')
      ->getStorage('field_config')
      ->load('entity_test.entity_test.' . static::FIELD_NAME);
    $settings = $field->get('settings');
    $settings['allow_custom'] = $allow;
    $field->set('settings', $settings)->save();
    $this->resetFieldCaches('na_region_host');
  }

  /**
   * Runs the authored extractor over the fixture field.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity to extract from.
   *
   * @return string[]
   *   The extracted text runs.
   */
  private function extract(EntityTest $entity): array {
    $buffer = new ComponentTextBuffer();
    $this->container->get('neo_alchemist_search.authored_text_extractor')
      ->extract($entity, static::FIELD_NAME, $buffer);
    return $buffer->toArray();
  }

}
