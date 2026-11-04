<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Component::createDuplicate() builds a clean, unsaved copy.
 *
 * EntityBase's implementation nulls the id of a cloned object, which this
 * class's typed string id rejects with a TypeError, and a cloned object would
 * keep the source's memoised shapes. The override rebuilds from values.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::createDuplicate()
 */
#[Group('neo_alchemist')]
final class ComponentDuplicateTest extends HybridFieldKernelTestBase {

  /**
   * A duplicate is new, unsaved and carries the source's settings.
   */
  public function testDuplicate(): void {
    $source = Component::load('na_leaf');
    $source->set('thumbnail', 'some_thumbnail');

    $duplicate = $source->createDuplicate();

    $this->assertInstanceOf(Component::class, $duplicate);
    $this->assertTrue($duplicate->isNew());
    $this->assertNotSame($source->uuid(), $duplicate->uuid());
    $this->assertSame($source->get('settings'), $duplicate->get('settings'));
    $this->assertSame($source->getComponentId(), $duplicate->getComponentId());
    $this->assertSame('', $duplicate->get('thumbnail'), 'The source\'s thumbnail file is not carried over.');
  }

  /**
   * A duplicate saves under a generated id and leaves the source alone.
   */
  public function testDuplicateSaves(): void {
    $source = Component::load('na_leaf');
    $duplicate = $source->createDuplicate();
    $duplicate->set('label', 'Leaf copy');
    $duplicate->save();

    $this->assertSame('na_leaf_2', $duplicate->id(), 'save() names a new component after its SDC.');
    $copy = Component::load('na_leaf_2');
    $this->assertSame('Leaf copy', $copy->label());
    $this->assertSame($source->get('settings'), $copy->get('settings'));
    $this->assertNotSame($source->uuid(), $copy->uuid());
    $this->assertSame($source->label(), Component::load('na_leaf')->label(), 'The source is unchanged.');
  }

}
