<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Entity\ComponentField;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist_block\Entity\AlchemistBlock;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * Move and library-position paths gain server-side URL generation.
 *
 * The editor's move (append a component in a direction) and positional library
 * (add before/after a sibling, optionally within a parent) paths had no
 * server-side URL generator: the client built them by concatenating suffixes
 * onto a base URL, which bypasses path processing entirely. This test pins that
 * both rels now resolve through the URL generator in every host scope — the
 * entity scope, the field-UI scope and the null-storage block scope — carrying
 * the direction, position and parent, and that a non-standard base path is
 * applied (the case string concatenation gets wrong).
 *
 * @see \Drupal\neo_alchemist\Entity\ComponentEntity::toUrl()
 * @see \Drupal\neo_alchemist\Entity\ComponentField::toUrl()
 * @see \Drupal\neo_alchemist\Entity\ComponentFieldConfig::toUrl()
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::toUrl()
 */
#[Group('neo_alchemist')]
class MoveAndLibraryPositionUrlTest extends HybridFieldKernelTestBase {

  /**
   * The block config entity id the block scope registers under.
   */
  private const BLOCK_ID = 'nab_test';

  /**
   * The block default layout's leaf instance uuid (a valid UUID: config store).
   */
  private const BLOCK_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'block',
    'neo_settings',
    'neo_config_file',
    'neo_alchemist',
    'neo_alchemist_test',
    'neo_alchemist_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A block with one na_leaf in its default layout, so the block scope has a
    // component instance to address and a field config to build library URLs
    // off. Saved so the bundle field info hook provides field_components.
    AlchemistBlock::create([
      'id' => self::BLOCK_ID,
      'label' => 'Test block',
      'components' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => self::BLOCK_UUID, 'component' => 'na_leaf'],
          ],
        ],
        'props' => [
          self::BLOCK_UUID => $this->leafProps('BLOCK TEXT'),
        ],
      ],
    ])->save();
    // The alchemist link templates are added by neo_alchemist_entity_type_alter
    // once a component-tree field exists; clear so the alter re-runs with the
    // fixture field present.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The entity-scope component instance for the fixture's seed leaf.
   */
  private function entityInstance(): ComponentEntity {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    $instance = $item->getComponent(static::SEED_UUID);
    $this->assertInstanceOf(ComponentEntity::class, $instance);
    return $instance;
  }

  /**
   * The field-config-scope tree item for the fixture field.
   */
  private function fieldConfigItem(): ComponentTreeItem {
    $fieldConfig = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[static::FIELD_NAME];
    $item = $fieldConfig->getFieldItem();
    $this->assertTrue($item->belongsToFieldConfig(), 'Premise: the item is in the field-config scope.');
    return $item;
  }

  /**
   * The field-config-scope component instance for the fixture's seed leaf.
   */
  private function fieldConfigInstance(): ComponentField {
    $instance = $this->fieldConfigItem()->getComponent(static::SEED_UUID);
    $this->assertInstanceOf(ComponentField::class, $instance);
    return $instance;
  }

  /**
   * The block-scope field config, built from the saved block.
   */
  private function blockFieldConfig(): AlchemistBlockFieldConfig {
    $block = AlchemistBlock::load(self::BLOCK_ID);
    $this->assertInstanceOf(AlchemistBlock::class, $block);
    return AlchemistBlockFieldConfig::createFromBlock($block);
  }

  /**
   * The block-scope component instance for the block's leaf.
   */
  private function blockInstance(): ComponentField {
    $instance = $this->blockFieldConfig()->getFieldItem()->getComponent(self::BLOCK_UUID);
    $this->assertInstanceOf(ComponentField::class, $instance);
    return $instance;
  }

  /**
   * The entity scope's move URL carries the direction and an optional parent.
   */
  public function testEntityScopeMoveUrl(): void {
    $url = $this->entityInstance()->toUrl('move', [
      'direction' => 'down',
      'query' => ['parent' => 'parent-uuid'],
    ]);

    $this->assertSame('entity.entity_test.alchemist.move', $url->getRouteName());
    $parameters = $url->getRouteParameters();
    $this->assertSame('down', $parameters['direction']);
    $this->assertSame(static::SEED_UUID, $parameters['neo_component']);
    // getKeyFromFieldname('field_alch') strips the field_ prefix.
    $this->assertSame('alch', $parameters['neo_field']);
    $this->assertSame(['parent' => 'parent-uuid'], $url->getOptions()['query']);
  }

  /**
   * The entity scope's library URL carries a before/after position and parent.
   */
  public function testEntityScopeLibraryUrl(): void {
    $url = $this->entityInstance()->getFieldItem()->toUrl('library', [
      'query' => ['before' => 'sibling-uuid', 'parent' => 'parent-uuid'],
    ]);

    $this->assertSame('entity.entity_test.alchemist.library', $url->getRouteName());
    $this->assertSame(
      ['before' => 'sibling-uuid', 'parent' => 'parent-uuid'],
      $url->getOptions()['query'],
    );
  }

  /**
   * The field-UI scope's move and library URLs carry the same payload.
   */
  public function testFieldUiScopeMoveAndLibraryUrls(): void {
    $move = $this->fieldConfigInstance()->toUrl('move', ['direction' => 'up']);
    $this->assertSame('entity.entity_test.field_ui.alchemist.move', $move->getRouteName());
    $this->assertSame('up', $move->getRouteParameters()['direction']);
    $this->assertSame(static::SEED_UUID, $move->getRouteParameters()['neo_component']);

    $library = $this->fieldConfigItem()->toUrl('library', [
      'query' => ['after' => 'sibling-uuid'],
    ]);
    $this->assertSame('entity.entity_test.field_ui.alchemist.library', $library->getRouteName());
    $this->assertSame(['after' => 'sibling-uuid'], $library->getOptions()['query']);
  }

  /**
   * The block scope's move and library URLs resolve to its own route family.
   */
  public function testBlockScopeMoveAndLibraryUrls(): void {
    $move = $this->blockInstance()->toUrl('move', ['direction' => 'up']);
    $this->assertSame('entity.neo_alchemist_block_host.field_ui.alchemist.move', $move->getRouteName());
    $this->assertSame('up', $move->getRouteParameters()['direction']);
    $this->assertSame(self::BLOCK_UUID, $move->getRouteParameters()['neo_component']);
    $this->assertSame(self::BLOCK_ID, $move->getRouteParameters()['neo_alchemist_block']);

    $library = $this->blockFieldConfig()->toUrl('library', [
      'query' => ['before' => 'sibling-uuid'],
    ]);
    $this->assertSame('entity.neo_alchemist_block_host.field_ui.alchemist.library', $library->getRouteName());
    $this->assertSame(['before' => 'sibling-uuid'], $library->getOptions()['query']);
  }

  /**
   * The block scope still refuses purge, matching the routes it registers.
   *
   * Purge is a structural no-op for a null-storage host and the block scope
   * registers no purge route; the generator's throw must keep enforcing that.
   */
  public function testBlockScopeStillRefusesPurge(): void {
    $this->expectException(EntityMalformedException::class);
    $this->blockFieldConfig()->toUrl('purge');
  }

  /**
   * A move URL with no direction is a caller bug, and fails loudly.
   */
  public function testMoveWithoutDirectionFails(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->entityInstance()->toUrl('move');
  }

  /**
   * The generated URLs go through the generator, so a base path is applied.
   *
   * Concatenation off a base URL bypasses path processing; generating each
   * route applies the site's base path. Under a non-standard base path the
   * string must carry it, which a hand-built suffix would not. Proven on both
   * generator mechanisms: the entity scope goes through Entity::toUrl(), the
   * block scope through Url::fromRoute() (the same code path the field-UI scope
   * uses — the field-UI scope's own routes need field_ui's base route, which
   * entity_test does not register alone, so the block scope stands in for that
   * mechanism).
   */
  public function testGeneratedUrlsCarryTheBasePath(): void {
    $entityInstance = $this->entityInstance();
    $blockInstance = $this->blockInstance();
    $blockFieldConfig = $this->blockFieldConfig();

    // Register the real route family and point the generator at a subdirectory
    // base path — what a site installed under /subdir reports. The URL
    // generator prepends the request context's base URL; concatenation off a
    // pre-built base would not reproduce it per route.
    $this->container->get('router.builder')->rebuild();
    $this->container->get('router.request_context')->setBaseUrl('/subdir');

    // Entity scope — Entity::toUrl().
    $move = $entityInstance->toUrl('move', ['direction' => 'up'])->toString();
    $this->assertStringStartsWith('/subdir/', $move, 'The entity-scope move URL carries the base path.');
    $this->assertStringContainsString('/move/' . static::SEED_UUID . '/up', $move);

    $library = $entityInstance->getFieldItem()
      ->toUrl('library', ['query' => ['before' => 'sibling-uuid']])
      ->toString();
    $this->assertStringStartsWith('/subdir/', $library, 'The entity-scope library URL carries the base path.');
    $this->assertStringContainsString('before=sibling-uuid', $library);

    // Block scope — Url::fromRoute(), shared with the field-UI scope.
    $blockMove = $blockInstance->toUrl('move', ['direction' => 'up'])->toString();
    $this->assertStringStartsWith('/subdir/', $blockMove, 'The block-scope move URL carries the base path.');
    $this->assertStringContainsString('/move/' . self::BLOCK_UUID . '/up', $blockMove);

    $blockLibrary = $blockFieldConfig
      ->toUrl('library', ['query' => ['after' => 'sibling-uuid']])
      ->toString();
    $this->assertStringStartsWith('/subdir/', $blockLibrary, 'The block-scope library URL carries the base path.');
    $this->assertStringContainsString('after=sibling-uuid', $blockLibrary);
  }

}
