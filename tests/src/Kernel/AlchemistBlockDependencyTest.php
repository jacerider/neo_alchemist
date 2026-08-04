<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist_block\Entity\AlchemistBlock;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting a component updates Alchemist blocks instead of deleting them.
 *
 * AlchemistBlock has always declared config dependencies on the components in
 * its tree, which means the config dependency system would delete the whole
 * block — and every placement of it — as soon as one component it contained
 * was removed. onDependencyRemoval() detaches the component instead.
 *
 * @see \Drupal\neo_alchemist_block\Entity\AlchemistBlock::onDependencyRemoval()
 */
#[Group('neo_alchemist')]
final class AlchemistBlockDependencyTest extends KernelTestBase {

  /**
   * The block's root-level instance uuids.
   */
  private const KEPT_UUID = 'kept-instance-uuid';
  private const DOOMED_UUID = 'doomed-instance-uuid';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block',
    // na_heading's style shape resolves a list_string field item.
    'options',
    'neo_settings',
    'neo_config_file',
    'neo_alchemist',
    'neo_alchemist_block',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    foreach (['na_leaf', 'na_heading'] as $id) {
      Component::create([
        'id' => $id,
        'label' => $id,
        'description' => $id,
        'component' => 'neo_alchemist_test:' . $id,
        'status' => TRUE,
      ])->save();
    }

    AlchemistBlock::create([
      'id' => 'na_test_block',
      'label' => 'Test block',
      'components' => [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => self::KEPT_UUID, 'component' => 'na_heading'],
            ['uuid' => self::DOOMED_UUID, 'component' => 'na_leaf'],
          ],
        ],
        'props' => [
          self::KEPT_UUID => ['status' => TRUE],
          self::DOOMED_UUID => ['status' => TRUE],
        ],
      ],
    ])->save();
  }

  /**
   * Loads the block straight from storage, as the config system does.
   */
  private function loadBlock(): ?AlchemistBlock {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_alchemist_block');
    $storage->resetCache(['na_test_block']);
    return $storage->load('na_test_block');
  }

  /**
   * The block declares a dependency on every component in its tree.
   */
  public function testBlockDeclaresComponentDependencies(): void {
    $dependencies = $this->loadBlock()->getDependencies()['config'] ?? [];
    $this->assertContains('neo_alchemist.neo_component.na_heading', $dependencies);
    $this->assertContains('neo_alchemist.neo_component.na_leaf', $dependencies);
  }

  /**
   * Deleting a component detaches it and leaves the block in place.
   */
  public function testDeletingComponentUpdatesTheBlock(): void {
    Component::load('na_leaf')->delete();

    $block = $this->loadBlock();
    $this->assertNotNull($block, 'The block survived the component deletion.');

    $values = $block->getComponentValues();
    $this->assertSame(
      [['uuid' => self::KEPT_UUID, 'component' => 'na_heading']],
      $values['tree'][ComponentTreeStructure::ROOT_UUID]
    );
    $this->assertSame([self::KEPT_UUID], array_keys($values['props']));
    $this->assertNotContains(
      'neo_alchemist.neo_component.na_leaf',
      $block->getDependencies()['config'] ?? [],
      'The dependency is dropped once the component is detached.'
    );
  }

  /**
   * Deleting every component empties the block without deleting it.
   */
  public function testDeletingAllComponentsKeepsTheBlock(): void {
    Component::load('na_leaf')->delete();
    Component::load('na_heading')->delete();

    $block = $this->loadBlock();
    $this->assertNotNull($block, 'The block survived losing all of its components.');
    $this->assertSame(
      [ComponentTreeStructure::ROOT_UUID => []],
      $block->getComponentValues()['tree']
    );
  }

}
