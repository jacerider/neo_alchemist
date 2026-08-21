<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Entity\ComponentField;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Routing\EditorRouteFamily;
use Drupal\neo_alchemist_block\Entity\AlchemistBlock;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The component emits each editor op as a record, in every host scope.
 *
 * Phase two turns the flat map of eight access booleans the component stamped
 * into the data-component attribute into eight records: each carries its
 * identifier, whether it is permitted, the URL it targets, and — copied from
 * the op vocabulary — its label, verb and position. This is the op inventory a
 * kernel test could not assert before: which ops are present, which are
 * permitted, and what URL each carries, for a component in the entity, field-UI
 * and block scopes.
 *
 * The access decision is unchanged, only its emission: the record's `permitted`
 * mirrors the same access() call the boolean did, and move up / move down keep
 * their strict position comparison, so an unknown position withholds the op.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::getEditorOps()
 * @see \Drupal\neo_alchemist\Entity\ComponentInstanceBase::editorOpUrl()
 * @see \Drupal\neo_alchemist\Routing\EditorOpInventory
 */
#[Group('neo_alchemist')]
class ComponentEmitsOpRecordsTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * The block config entity id the block scope registers under.
   */
  private const BLOCK_ID = 'nab_test';

  /**
   * The block default layout's leaf instance uuid (a valid UUID: config store).
   */
  private const BLOCK_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * The eight ops in the order the inventory declares them.
   */
  private const OP_IDS = [
    'edit',
    'sort',
    'clone',
    'delete',
    'add-before',
    'add-after',
    'move-up',
    'move-down',
  ];

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
    // An admin current user, so the field-UI and block scopes' "administer …
    // fields" access resolves allowed and the position-withholding test below
    // is a real flip (permitted → withheld) rather than a vacuous false.
    $this->setUpCurrentUser([], [], TRUE);
    // A block with one na_leaf in its default layout, so the block scope has a
    // component instance to address and a field config to build URLs off.
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
    // fixture field present and the entity scope can build alchemist.* URLs.
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * The route family the emitted URLs must agree with.
   */
  private function family(): EditorRouteFamily {
    return $this->container->get('neo_alchemist.editor_route_family');
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
   * The field-config-scope component instance for the fixture's seed leaf.
   */
  private function fieldConfigInstance(): ComponentField {
    $fieldConfig = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[static::FIELD_NAME];
    $item = $fieldConfig->getFieldItem();
    $this->assertTrue($item->belongsToFieldConfig(), 'Premise: the item is in the field-config scope.');
    $instance = $item->getComponent(static::SEED_UUID);
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
   * The three host scopes: [label => [scope const, entity type id, instance]].
   */
  private function scopes(): array {
    return [
      'entity' => [EditorRouteFamily::SCOPE_ENTITY, 'entity_test', $this->entityInstance()],
      'field-UI' => [EditorRouteFamily::SCOPE_FIELD_UI, 'entity_test', $this->fieldConfigInstance()],
      'block' => [EditorRouteFamily::SCOPE_BLOCK, 'neo_alchemist_block_host', $this->blockInstance()],
    ];
  }

  /**
   * Each scope emits all eight ops as records, each carrying its rel's URL.
   *
   * The presence and shape of the inventory, and the URL each op resolves to,
   * are asserted for a real component instance in each host scope — the op
   * inventory that had no coverage at any level before. Each URL targets the
   * route the route family spells for the op's rel in that scope, so the
   * emission and the routes cannot disagree; the add ops carry the sibling as a
   * before/after query, the move ops the direction as a parameter.
   */
  public function testEachScopeEmitsEightOpRecordsWithUrls(): void {
    $inventory = $this->container->get('neo_alchemist.editor_op_inventory');

    foreach ($this->scopes() as $name => [$scope, $entityTypeId, $instance]) {
      $records = $instance->getEditorOps(FALSE, FALSE);
      $this->assertSame(self::OP_IDS, array_keys($records), "$name: the eight ops, in inventory order");

      foreach ($records as $id => $record) {
        $op = $inventory->getOp($id);
        $this->assertSame(
          ['id', 'permitted', 'url', 'label', 'verb', 'position'],
          array_keys($record),
          "$name $id: the record's fields",
        );
        $this->assertSame($op->id, $record['id'], "$name $id: id");
        $this->assertSame($op->label, $record['label'], "$name $id: label from the inventory");
        $this->assertSame($op->verb, $record['verb'], "$name $id: verb from the inventory");
        $this->assertSame($op->position, $record['position'], "$name $id: position from the inventory");
        $this->assertIsBool($record['permitted'], "$name $id: permitted is a decision");

        // The URL targets the route the family spells for this op's rel — the
        // cross-check that keeps the vocabulary and the routes in step.
        $this->assertInstanceOf(Url::class, $record['url'], "$name $id: carries a URL");
        $this->assertSame(
          $this->family()->routeName($scope, $entityTypeId, $op->rel),
          $record['url']->getRouteName(),
          "$name $id: URL targets its rel route",
        );
      }

      // The add ops append the sibling (this instance) as a before/after query.
      $this->assertSame(
        ['before' => $instance->uuid()],
        $records['add-before']['url']->getOptions()['query'] ?? NULL,
        "$name: add-before carries the sibling as a before query",
      );
      $this->assertSame(
        ['after' => $instance->uuid()],
        $records['add-after']['url']->getOptions()['query'] ?? NULL,
        "$name: add-after carries the sibling as an after query",
      );
      // The move ops carry the direction as a route parameter.
      $this->assertSame('up', $records['move-up']['url']->getRouteParameters()['direction'], "$name: move-up direction");
      $this->assertSame('down', $records['move-down']['url']->getRouteParameters()['direction'], "$name: move-down direction");
    }
  }

  /**
   * The record's `permitted` mirrors the same access() call the boolean did.
   *
   * Access decisions are unchanged; only their emission is. Each op's permitted
   * value equals the live access decision for the operation it maps to, in the
   * same order the flat map computed them. This is the invariant that must hold
   * whatever the account can do: the emission cannot offer an op the access
   * layer denies, nor withhold one it allows.
   */
  public function testPermittedMirrorsTheAccessDecision(): void {
    foreach ($this->scopes() as $name => [, , $instance]) {
      $records = $instance->getEditorOps(FALSE, FALSE);

      $this->assertSame($instance->access('update'), $records['edit']['permitted'], "$name: edit ↔ update");
      $this->assertSame($instance->access('delete'), $records['delete']['permitted'], "$name: delete");
      $this->assertSame($instance->access('sort'), $records['sort']['permitted'], "$name: sort");
      $this->assertSame($instance->access('clone'), $records['clone']['permitted'], "$name: clone");
      $this->assertSame($instance->access('create'), $records['add-before']['permitted'], "$name: add-before ↔ create");
      $this->assertSame($instance->access('create'), $records['add-after']['permitted'], "$name: add-after ↔ create");
      // Move maps to sort access, gated by a known, non-boundary position.
      $this->assertSame($instance->access('sort'), $records['move-up']['permitted'], "$name: move-up ↔ sort (middle)");
      $this->assertSame($instance->access('sort'), $records['move-down']['permitted'], "$name: move-down ↔ sort (middle)");
    }
  }

  /**
   * Move up and move down are withheld at the boundary and on an unknown spot.
   *
   * The strict position comparison is what makes the chrome tell the truth: an
   * offered move on the first or last component is a no-op, and an unknown
   * position (the caller did not say where the component sits) fails closed
   * rather than open. Asserted in the field-UI and block scopes, where the
   * account genuinely has sort access, so the false below is a real flip from
   * the permitted middle case rather than an already-denied op.
   */
  public function testPositionWithholdsTheMoveOps(): void {
    foreach (['field-UI', 'block'] as $name) {
      [, , $instance] = $this->scopes()[$name];
      $this->assertTrue($instance->access('sort'), "$name premise: the account can sort");

      $middle = $instance->getEditorOps(FALSE, FALSE);
      $this->assertTrue($middle['move-up']['permitted'], "$name: move-up offered in the middle");
      $this->assertTrue($middle['move-down']['permitted'], "$name: move-down offered in the middle");

      $first = $instance->getEditorOps(TRUE, FALSE);
      $this->assertFalse($first['move-up']['permitted'], "$name: move-up withheld on the first component");
      $this->assertTrue($first['move-down']['permitted'], "$name: move-down still offered on the first");

      $last = $instance->getEditorOps(FALSE, TRUE);
      $this->assertFalse($last['move-down']['permitted'], "$name: move-down withheld on the last component");

      $unknown = $instance->getEditorOps(NULL, NULL);
      $this->assertFalse($unknown['move-up']['permitted'], "$name: move-up withheld on an unknown position");
      $this->assertFalse($unknown['move-down']['permitted'], "$name: move-down withheld on an unknown position");
    }
  }

  /**
   * The records reach the data-component attribute, which keeps its name.
   *
   * End to end: rendering the component in the manage preview stamps the op
   * records into the same data-component attribute, now as string URLs. The
   * attribute's name is unchanged — only the structure inside it — so the
   * change stays where the client already looks.
   */
  public function testEmittedAttributeCarriesTheRecords(): void {
    // Register the route family so the URLs render to strings.
    $this->container->get('router.builder')->rebuild();

    $instance = $this->entityInstance();
    $instance->setPreview(TRUE);
    $build = $instance->toRenderable(FALSE, FALSE);

    $attributes = $build['#props']['attributes'];
    $this->assertInstanceOf(Attribute::class, $attributes);
    $this->assertTrue($attributes->offsetExists('data-component'), 'The attribute keeps its name.');

    $data = Json::decode($attributes->offsetGet('data-component')->value());
    $this->assertIsArray($data);
    $this->assertArrayHasKey('ops', $data);
    $this->assertSame(self::OP_IDS, array_keys($data['ops']), 'All eight records reach the attribute.');

    $edit = $data['ops']['edit'];
    $this->assertSame(
      ['id', 'permitted', 'url', 'label', 'verb', 'position'],
      array_keys($edit),
      'The emitted record keeps every field.',
    );
    // The URL renders to a string in the attribute and targets the op path.
    $this->assertIsString($edit['url'], 'The URL is a generated string in the attribute.');
    $this->assertStringContainsString('/edit/', $edit['url']);
    $this->assertStringContainsString('/alchemist/', $edit['url']);
  }

}
