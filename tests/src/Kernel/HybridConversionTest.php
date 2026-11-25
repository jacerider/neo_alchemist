<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\Group;

/**
 * A layout saved in custom mode renders again once converted to hybrid.
 *
 * The fixture field is hybrid. A row written the way custom mode wrote it (a
 * whole tree, under the entity's own uuids) is invisible to it: the entity
 * renders the default's seed instead of what its author built. Converting the
 * row brings the authored content back, and a dry run changes nothing.
 *
 * @see \Drupal\neo_alchemist\HybridConversion
 */
#[Group('neo_alchemist')]
class HybridConversionTest extends HybridFieldKernelTestBase {

  /**
   * Stores a custom-mode row for a new entity and returns the entity id.
   */
  private function storeCustomRow(array $tree): int {
    $entity = $this->createTestEntity();
    $database = $this->container->get('database');
    $table = 'entity_test__' . static::FIELD_NAME;
    $database->delete($table)->condition('entity_id', $entity->id())->execute();
    $database->insert($table)->fields([
      'bundle' => 'entity_test',
      'deleted' => 0,
      'entity_id' => $entity->id(),
      'revision_id' => $entity->id(),
      'langcode' => $entity->language()->getId(),
      'delta' => 0,
      static::FIELD_NAME . '_tree' => json_encode($tree),
      static::FIELD_NAME . '_props' => json_encode([
        'own-host' => ['status' => TRUE, 'props' => []],
        'authored' => $this->leafProps('AUTHORED TEXT'),
      ]),
    ])->execute();
    $this->container->get('entity_type.manager')->getStorage('entity_test')->resetCache();
    return (int) $entity->id();
  }

  /**
   * A custom-mode tree: the entity's own host, one authored leaf in body.
   */
  private function customTree(string $rootComponent = 'na_region_host'): array {
    return [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'own-host', 'component' => $rootComponent],
      ],
      'own-host' => [
        'body' => [['uuid' => 'authored', 'component' => 'na_leaf']],
      ],
    ];
  }

  /**
   * The field item of a freshly loaded entity.
   */
  private function item(int $id): ComponentTreeItem {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$id]);
    $item = $storage->load($id)->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  /**
   * Runs the conversion over the fixture field.
   */
  private function convert(bool $apply): array {
    return $this->container->get('neo_alchemist.hybrid_conversion')
      ->convert('entity_test', static::FIELD_NAME, NULL, $apply);
  }

  /**
   * A dry run reports the row and leaves it unrendered.
   */
  public function testDryRunChangesNothing(): void {
    $id = $this->storeCustomRow($this->customTree());
    $this->assertNull($this->item($id)->getComponent('authored'), 'Premise: hybrid mode does not see the custom row.');

    $report = $this->convert(FALSE);
    $this->assertSame(['converted'], array_column($report, 'status'));
    $this->assertSame('body: 1', $report[0]['detail']);
    $this->assertNull($this->item($id)->getComponent('authored'), 'A dry run writes nothing.');
  }

  /**
   * Applying brings the authored content back, in place of the seed.
   */
  public function testApplyRestoresTheAuthoredContent(): void {
    $id = $this->storeCustomRow($this->customTree());
    $this->convert(TRUE);

    $item = $this->item($id);
    $this->assertInstanceOf(ComponentEntity::class, $item->getComponent('authored'));
    $this->assertNull($item->getComponent(static::SEED_UUID), 'The default seed gives way to the authored region.');
    $this->assertInstanceOf(ComponentEntity::class, $item->getComponent(static::HOST_UUID), 'The default owner renders.');
    $this->assertNull($item->getComponent('own-host'), 'The entity copy of the owner is gone.');

    $this->assertSame(['already'], array_column($this->convert(TRUE), 'status'), 'A second run has nothing to do.');
  }

  /**
   * A row whose locked structure differs is reported and left as it was.
   */
  public function testMismatchIsLeftAlone(): void {
    $id = $this->storeCustomRow($this->customTree('na_two_region'));
    $before = $this->container->get('database')
      ->select('entity_test__' . static::FIELD_NAME, 't')
      ->fields('t', [static::FIELD_NAME . '_tree'])
      ->condition('entity_id', $id)
      ->execute()
      ->fetchField();

    $report = $this->convert(TRUE);
    $this->assertSame(['mismatch'], array_column($report, 'status'));
    $this->assertStringContainsString('na_two_region', $report[0]['detail']);

    $after = $this->container->get('database')
      ->select('entity_test__' . static::FIELD_NAME, 't')
      ->fields('t', [static::FIELD_NAME . '_tree'])
      ->condition('entity_id', $id)
      ->execute()
      ->fetchField();
    $this->assertSame($before, $after);
  }

}
