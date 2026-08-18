<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestRev;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests detection of placements whose component no longer exists.
 *
 * The failure being guarded is silence, not breakage: a tree that names a
 * missing component renders nothing and reports nothing, so the only way to
 * notice is to already know what the page used to look like. Every assertion
 * here is about that state being *findable*.
 */
#[Group('neo_alchemist')]
class DanglingComponentDataTest extends KernelTestBase {

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
  ];

  /**
   * The host entity carrying a component tree.
   */
  protected EntityTestRev $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('user');
    $this->createComponentField();
  }

  /**
   * Attaches a component tree field to the test entity type.
   */
  protected function createComponentField(): void {
    $this->container->get('entity_type.manager')->getStorage('field_storage_config')->create([
      'field_name' => 'field_tree',
      'entity_type' => 'entity_test_rev',
      'type' => 'neo_component_tree',
      'cardinality' => 1,
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('field_config')->create([
      'field_name' => 'field_tree',
      'entity_type' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'label' => 'Tree',
      // Customizable, or ComponentUsage treats the row as inert and ignores it.
      'settings' => ['allow_custom' => TRUE],
    ])->save();
  }

  /**
   * Creates a component entity, returning the id it was actually given.
   */
  protected function createComponent(): string {
    $entity = $this->container->get('entity_type.manager')->getStorage('neo_component')->create([
      'label' => 'Probe',
      'description' => 'Fixture.',
      'group' => 'general',
      'component' => 'neo_alchemist_test:na_leaf',
      'status' => TRUE,
    ]);
    $entity->save();
    return $entity->id();
  }

  /**
   * Places a component on a host entity.
   */
  protected function placeComponent(string $componentId): void {
    $this->host = EntityTestRev::create(['type' => 'entity_test_rev', 'name' => 'Host']);
    $this->host->set('field_tree', [
      'tree' => json_encode([
        'a548b48d-0000-0000-0000-000000000000' => [
          ['uuid' => '11111111-2222-3333-4444-555555555555', 'component' => $componentId],
        ],
      ]),
      // Must encode as a JSON object; ComponentPropsValues asserts on '{'.
      'props' => json_encode((object) []),
    ]);
    $this->host->save();
  }

  /**
   * Rewrites the stored tree to name a component that does not exist.
   *
   * Written straight to storage, the way a half-applied rename leaves it —
   * going through the entity API would run validation, which is exactly the
   * check that never fires on already-saved content.
   */
  protected function breakTree(string $from, string $to): void {
    $this->container->get('database')->update('entity_test_rev__field_tree')
      ->expression('field_tree_tree', 'REPLACE(field_tree_tree, :a, :b)', [
        ':a' => '"component":"' . $from . '"',
        ':b' => '"component":"' . $to . '"',
      ])
      ->execute();
  }

  /**
   * Gets the detector.
   */
  protected function detector() {
    return $this->container->get('neo_alchemist.dangling_component_data');
  }

  /**
   * Tests that a healthy site reports nothing.
   */
  public function testHealthySiteReportsNothing(): void {
    $this->placeComponent($this->createComponent());
    $this->assertSame([], $this->detector()->scan(TRUE));
    $this->assertSame(0, $this->detector()->count(TRUE));
  }

  /**
   * Tests that a placement of a missing component is found.
   */
  public function testDanglingPlacementIsFound(): void {
    $id = $this->createComponent();
    $this->placeComponent($id);
    $this->breakTree($id, 'never_existed');

    $found = $this->detector()->scan(TRUE);
    $this->assertArrayHasKey('never_existed', $found, 'The missing component was named.');
    $this->assertSame(1, $found['never_existed']['count']);
    $this->assertSame(1, $this->detector()->count());
    // The report has to point somewhere, or it cannot be acted on.
    $this->assertNotEmpty($found['never_existed']['places']);
  }

  /**
   * Tests that the report is plain data.
   *
   * Its consumers run without a render context — hook_requirements under
   * `drush core:requirements`, and the integrity command — and usage labels
   * can be render objects that throw when stringified there.
   */
  public function testReportIsPlainData(): void {
    $id = $this->createComponent();
    $this->placeComponent($id);
    $this->breakTree($id, 'never_existed');

    $found = $this->detector()->scan(TRUE);
    $this->assertIsString(json_encode($found), 'The report survives json_encode.');
    foreach ($found['never_existed']['places'] as $place) {
      $this->assertIsString($place['label']);
      $this->assertIsString($place['url']);
      $this->assertIsString($place['context']);
    }
  }

  /**
   * Tests that a component still in use is never reported as dangling.
   *
   * The guard against the report crying wolf: an id that resolves is fine no
   * matter how many places hold it.
   */
  public function testExistingComponentIsNeverDangling(): void {
    $id = $this->createComponent();
    $this->placeComponent($id);
    $this->breakTree($id, 'never_existed');

    $found = $this->detector()->scan(TRUE);
    $this->assertArrayNotHasKey($id, $found);
  }

}
