<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;

/**
 * Base class for Kernel tests exercising a real neo_component_tree field.
 *
 * Provides a hybrid-ready fixture: an entity_test field whose default layout
 * places one na_region_host (uuid HOST_UUID) with a na_leaf seed (SEED_UUID)
 * in its body region, flagged entity-customizable via region_custom.
 *
 * Two traps this class encodes so tests don't rediscover them:
 * - region_custom is enabled by RAW CONFIG after the component's first save.
 *   Component::preSave() regenerates settings.props from the live shapes on
 *   save, wiping hand-set plugin configuration.
 * - Always reach the field through the entity ($entity->get(...)). A
 *   directly loaded FieldConfig is NOT a ComponentFieldConfig — the wrap
 *   happens in neo_alchemist_entity_bundle_field_info_alter() via the
 *   entity field manager — and NeoComponentTreeList::setValue() silently
 *   bails when the definition isn't a ComponentFieldConfigInterface.
 */
abstract class HybridFieldKernelTestBase extends KernelTestBase {

  /**
   * The field name.
   */
  protected const FIELD_NAME = 'field_alch';

  /**
   * The default layout's region-host instance uuid.
   */
  protected const HOST_UUID = 'host-instance-uuid';

  /**
   * The default layout's seed leaf instance uuid.
   */
  protected const SEED_UUID = 'seed-instance-uuid';

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');

    foreach (['na_region_host', 'na_two_region', 'na_leaf'] as $id) {
      $this->createFixtureComponent($id);
    }
    $this->flagRegion();

    FieldStorageConfig::create([
      'field_name' => static::FIELD_NAME,
      'entity_type' => 'entity_test',
      'type' => 'neo_component_tree',
    ])->save();
    FieldConfig::create([
      'field_name' => static::FIELD_NAME,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'settings' => [
        'allow_custom' => FALSE,
        'sizes' => [],
        'defaults' => $this->defaultLayout(),
      ],
    ])->save();
  }

  /**
   * The field default layout: root → host, host.body → seed.
   *
   * @return array
   *   The decoded 'tree' + 'props' arrays stored in the defaults setting.
   */
  protected function defaultLayout(): array {
    return [
      'tree' => [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => static::HOST_UUID, 'component' => 'na_region_host'],
        ],
        static::HOST_UUID => [
          'body' => [['uuid' => static::SEED_UUID, 'component' => 'na_leaf']],
        ],
      ],
      'props' => [
        static::HOST_UUID => [
          'status' => TRUE,
          'props' => [
            'heading' => ['ref' => 'string', 'value' => ['value' => 'DEFAULT HEADING']],
          ],
        ],
        static::SEED_UUID => [
          'status' => TRUE,
          'props' => [
            'text' => ['ref' => 'string', 'value' => ['value' => 'SEED TEXT']],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds a canonical per-instance props entry for a na_leaf component.
   *
   * Props use the canonical wrapper (status + props, each prop carrying
   * ref/value/options) — raw values silently render the schema examples
   * instead.
   *
   * @param string $text
   *   The leaf's text value.
   *
   * @return array
   *   The per-uuid props entry.
   */
  protected function leafProps(string $text): array {
    return [
      'status' => TRUE,
      'props' => [
        'text' => ['ref' => 'string', 'value' => ['value' => $text]],
      ],
    ];
  }

  /**
   * Creates a fixture neo_component config entity.
   */
  protected function createFixtureComponent(string $id): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load($id)) {
      Component::create([
        'id' => $id,
        'label' => $id,
        'description' => $id,
        'component' => 'neo_alchemist_test:' . $id,
        'status' => TRUE,
      ])->save();
    }
  }

  /**
   * Flags a region prop entity-customizable via raw config.
   */
  protected function flagRegion(string $componentId = 'na_region_host', string $shapeId = 'body'): void {
    $this->config("neo_alchemist.neo_component.$componentId")
      ->set("settings.props.$shapeId.plugins.$shapeId.region_custom", [
        'id' => 'region_custom',
        'settings' => [],
      ])
      ->save();
    $this->resetFieldCaches($componentId);
  }

  /**
   * Removes the region_custom flag via raw config.
   */
  protected function unflagRegion(string $componentId = 'na_region_host', string $shapeId = 'body'): void {
    $this->config("neo_alchemist.neo_component.$componentId")
      ->clear("settings.props.$shapeId.plugins.$shapeId.region_custom")
      ->save();
    $this->resetFieldCaches($componentId);
  }

  /**
   * Drops every cache between component config and field definitions.
   *
   * The custom-region lookup memoises per ComponentFieldConfig object and
   * the entity field manager caches those objects, so a flag change is
   * invisible to already-derived definitions and already-loaded entities.
   */
  protected function resetFieldCaches(string $componentId): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $entityTypeManager->getStorage('neo_component')->resetCache([$componentId]);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $entityTypeManager->getStorage('entity_test')->resetCache();
  }

  /**
   * Creates and saves a test entity.
   */
  protected function createTestEntity(): EntityTest {
    $entity = EntityTest::create([]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * Reloads an entity bypassing the static cache.
   */
  protected function reloadEntity(EntityTest $entity): EntityTest {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$entity->id()]);
    $reloaded = $storage->load($entity->id());
    $this->assertInstanceOf(EntityTest::class, $reloaded);
    return $reloaded;
  }

  /**
   * Reads the entity's raw stored field value straight from the database.
   *
   * A loaded entity's field value has already been re-merged by
   * setHybridValue(), which masks what was actually persisted — the whole
   * point of most assertions here.
   *
   * @return array|null
   *   ['tree' => array, 'props' => array], or NULL when nothing is stored.
   */
  protected function rawStoredValue(EntityTest $entity): ?array {
    $row = $this->container->get('database')
      ->select('entity_test__' . static::FIELD_NAME, 'f')
      ->fields('f', [static::FIELD_NAME . '_tree', static::FIELD_NAME . '_props'])
      ->condition('entity_id', $entity->id())
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return [
      'tree' => Json::decode($row[static::FIELD_NAME . '_tree'] ?? '') ?: [],
      'props' => Json::decode($row[static::FIELD_NAME . '_props'] ?? '') ?: [],
    ];
  }

  /**
   * Authors content into the host's body region and saves the entity.
   *
   * Builds the full merged tree (what in-session edits produce) with the
   * given tuples replacing the region content, sets it through the list's
   * hybrid setter, and saves.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   * @param array $tuples
   *   The region tuples, e.g. [['uuid' => 'x', 'component' => 'na_leaf']].
   * @param array $props
   *   Props for the authored tuples, keyed by uuid.
   */
  protected function authorRegionContent(EntityTest $entity, array $tuples, array $props): void {
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = $tuples;
    $entity->set(static::FIELD_NAME, [
      ['tree' => $tree, 'props' => $defaults['props'] + $props],
    ]);
    $entity->save();
  }

  /**
   * Premise guard: the fixture field really is hybrid.
   */
  protected function assertFieldIsHybrid(EntityTest $entity): ComponentFieldConfigInterface {
    $definition = $entity->get(static::FIELD_NAME)->getFieldDefinition();
    $this->assertInstanceOf(ComponentFieldConfigInterface::class, $definition, 'Premise: the entity field manager wrapped the field config.');
    /** @var \Drupal\neo_alchemist\ComponentFieldConfigInterface $definition */
    $this->assertTrue($definition->isHybrid(), 'Premise: the fixture field is hybrid.');
    return $definition;
  }

}
