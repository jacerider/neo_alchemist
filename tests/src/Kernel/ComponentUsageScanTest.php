<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the content scan counts as usage, per field mode.
 *
 * The usage report answers "is it safe to change or delete this component",
 * so a false positive is as harmful as a miss: it makes a component that
 * nothing renders look load-bearing. The stored column alone cannot answer
 * that, because whether a row is ever read back depends on the field's mode:
 * - custom: the entity owns the whole tree — the row is the usage;
 * - hybrid: the entity owns only the flagged regions, and only that subset is
 *   stored, so the default layout's own components are NOT content usage;
 * - locked: the row is never read back at all, so it is never usage.
 *
 * The locked case is asserted against a force-written row, so it pins the
 * scan independently of the preSave() guard that stops such rows being
 * written in the first place — data predating that guard is still out there.
 *
 * @see \Drupal\neo_alchemist\ComponentUsage::scanContent()
 */
#[Group('neo_alchemist')]
class ComponentUsageScanTest extends HybridFieldKernelTestBase {

  /**
   * Runs the content scan and returns the rows naming a component.
   *
   * The scan is reached directly rather than through ::getUsages() because
   * that also runs the default-layout scan, which builds icon markup and so
   * needs a render context — irrelevant to what is under test here.
   *
   * @param string $componentId
   *   The component config entity id.
   *
   * @return array
   *   The matching usage rows.
   */
  private function scanContentFor(string $componentId): array {
    $usage = $this->container->get('neo_alchemist.component_usage');
    $rows = (new \ReflectionMethod($usage, 'scanContent'))->invoke($usage);
    return array_values(array_filter(
      $rows,
      static fn(array $row): bool => in_array($componentId, $row['components'], TRUE),
    ));
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
   * Writes a row straight to the field table, bypassing the field API.
   */
  private function forceStoredRow(EntityTest $entity, array $tree, array $props): void {
    $this->container->get('database')
      ->insert('entity_test__' . static::FIELD_NAME)
      ->fields([
        'bundle' => $entity->bundle(),
        'deleted' => 0,
        'entity_id' => $entity->id(),
        'revision_id' => $entity->getRevisionId() ?? $entity->id(),
        'langcode' => $entity->language()->getId(),
        'delta' => 0,
        static::FIELD_NAME . '_tree' => Json::encode($tree),
        static::FIELD_NAME . '_props' => Json::encode($props),
      ])
      ->execute();
  }

  /**
   * Locked mode: a stored row is not usage, however it got there.
   */
  public function testLockedRowIsNotUsage(): void {
    $this->configureField(FALSE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();

    // Exactly the shape the pre-fix insert path left behind: a verbatim copy
    // of the default layout, plus a component the layout no longer contains.
    $this->forceStoredRow($entity, [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'default-leaf', 'component' => 'na_leaf'],
        ['uuid' => 'stale', 'component' => 'na_two_region'],
      ],
    ], [
      'default-leaf' => $this->leafProps('DEFAULT LEAF'),
      'stale' => [],
    ]);

    $this->assertSame([], $this->scanContentFor('na_leaf'), 'A locked row is not content usage.');
    $this->assertSame([], $this->scanContentFor('na_two_region'), 'A stale component in a locked row is not resurrected as usage.');
  }

  /**
   * Custom mode: the entity owns the whole tree, so the row is usage.
   */
  public function testCustomRowIsUsage(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'custom-leaf', 'component' => 'na_leaf'],
          ],
        ],
        'props' => ['custom-leaf' => $this->leafProps('CUSTOM')],
      ],
    ]);
    $entity->save();

    $rows = $this->scanContentFor('na_leaf');
    $this->assertCount(1, $rows, 'The customized entity is reported once.');
    $this->assertSame((string) $entity->label(), $rows[0]['label']);
  }

  /**
   * Hybrid mode: the authored region is usage, the default layout is not.
   */
  public function testHybridReportsOnlyTheEntityOwnedSubset(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $this->authorRegionContent($entity, [
      ['uuid' => 'authored-leaf', 'component' => 'na_leaf'],
    ], [
      'authored-leaf' => $this->leafProps('AUTHORED'),
    ]);

    $this->assertCount(1, $this->scanContentFor('na_leaf'), 'The component the editor placed in the region is content usage.');
    $this->assertSame(
      [],
      $this->scanContentFor('na_region_host'),
      'The anchor comes from the default layout, so it is not content usage — only the stored subset is, and its root is empty.',
    );
  }

  /**
   * A stranded row is not content usage, and the inert scan is what sees it.
   *
   * The pairing that matters: ::scanContent() must stay silent about a locked
   * field's row while InertComponentData reports it, so the usage report can
   * show it without counting it. The page-level split between the two — the
   * "Stored but not rendered" section, and its exclusion from the usage tally
   * — is verified against the running site, because ::getUsages() labels
   * default-layout rows with entity-type icons and so needs neo_icon, which
   * does not install in this kernel environment.
   */
  public function testStrandedRowIsInertNotContent(): void {
    $this->configureField(TRUE, $this->leafOnlyLayout());
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
    $this->assertCount(1, $this->scanContentFor('na_two_region'), 'While customizable, the row is content usage.');

    // Lock the field: the stored tree stops rendering but stays put.
    $this->configureField(FALSE, $this->leafOnlyLayout());
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();

    $this->assertSame([], $this->scanContentFor('na_two_region'), 'Once locked, the row is no longer content usage.');
    $inert = $this->container->get('neo_alchemist.inert_component_data')->scan();
    $this->assertCount(1, $inert, 'The inert scan picks it up instead.');
    $this->assertContains('na_two_region', $inert[0]['components']);
  }

  /**
   * Hybrid mode: a pristine entity stores nothing, so it is not usage.
   */
  public function testPristineHybridIsNotUsage(): void {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);

    $this->assertSame([], $this->scanContentFor('na_leaf'), 'An entity that never customized its region is not content usage.');
  }

}
