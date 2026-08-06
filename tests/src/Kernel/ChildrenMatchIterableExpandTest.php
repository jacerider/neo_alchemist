<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that an "Expand" on an ARRAY child produces a delta-keyed list.
 *
 * ComponentValueChildrenMatchTrait::fetchChildrenMatchValues() builds either a
 * delta-keyed LIST or a flat property MAP, and picks between them from the
 * shape it is filling. Every recursion (`_expand`, `_reference`) is handed the
 * ROOT children-match shape — that is what the child-state calls are keyed by —
 * so reading iterability off that argument answered for the root instead of for
 * the child actually being filled. An array child of an object root therefore
 * collapsed to the bare property map of its first item.
 *
 * Two things went wrong, and only the second one was visible:
 * - ArrayShape keeps integer deltas only, so the string-keyed map it was handed
 *   yielded no items at all: the prop rendered as nothing, silently.
 * - ArrayShape::getDefaultSchemaValue() tops each item up with the schema's own
 *   per-property examples. Handed a scalar where an item was expected, that
 *   write is a hard TypeError — a WSOD on every screen that loads the
 *   component's shapes, including the config form used to fix it and the
 *   component save itself.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueChildrenMatchTrait
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape::getDefaultSchemaValue()
 */
#[Group('neo_alchemist')]
class ChildrenMatchIterableExpandTest extends KernelTestBase {

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
  }

  /**
   * Builds an aggregated component with the query provider on `_aggregate`.
   *
   * Aggregation is what puts an object shape above the array prop: the whole
   * schema becomes the children of a synthetic `_aggregate` object, so `items`
   * is reached as a CHILD and its "Expand" recursion is the case under test.
   *
   * `title` is bound to a raw literal on purpose. A scalar landing where an
   * item was expected is what turned the collapse from "renders nothing" into
   * a fatal.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    // Component::save() re-derives the id of a NEW component from its SDC id,
    // so the requested id above is not the one it lands on — the fixture module
    // already ships `na_array_required`, which pushes this to `..._2`. Read the
    // id back rather than assuming it.
    $component = Component::create([
      'label' => 'Aggregated array fixture',
      'description' => 'Aggregated array fixture',
      'component' => 'neo_alchemist_test:na_array_required',
      'status' => TRUE,
      'aggregate' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props._aggregate.plugins._aggregate', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => [
            'entity_type' => 'entity_test',
            'length' => 10,
            'shape_fields' => [
              'items' => [
                'field' => '_expand',
                'shape_fields' => [
                  'title' => ['field' => '_raw:string', 'string' => 'RAW TITLE'],
                  'count' => ['field' => '_default'],
                ],
              ],
            ],
          ],
        ],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * An expanded array child resolves to a delta-keyed list of items.
   */
  public function testExpandedArrayChildKeepsDeltas(): void {
    EntityTest::create(['name' => 'One'])->save();

    $component = $this->buildComponent();
    $value = $component->getPropShape('_aggregate')->getDefaultValue();

    $this->assertArrayHasKey('items', $value);
    $this->assertArrayHasKey(
      0,
      $value['items'],
      'The expanded array child is a list keyed by delta, not the bare property map of its first item — ArrayShape reads integer deltas only, so a map renders no items at all.',
    );
    $this->assertSame('RAW TITLE', $value['items'][0]['title']);
  }

  /**
   * A non-iterable expanded child still resolves to a flat property map.
   *
   * The counterpart assertion: the fix must not turn every `_expand` into a
   * list. `title` here is a child of the array ITEM, and the item is a plain
   * object, so its own expansion stays flat.
   */
  public function testExpandedObjectChildStaysFlat(): void {
    EntityTest::create(['name' => 'One'])->save();

    $component = $this->buildComponent();
    $value = $component->getPropShape('_aggregate')->getDefaultValue();

    $this->assertArrayNotHasKey(
      0,
      $value['items'][0],
      'An object item is a property map; wrapping it in a delta would push the whole tree down a level.',
    );
  }

  /**
   * Loading every shape does not fatal, so the component stays editable.
   *
   * Component::preSave() walks getPropShapesAll(), so a throw here is a
   * component that cannot be saved — and the config form that would let you
   * correct the setting cannot be rendered either.
   */
  public function testAllShapesLoadWithoutFatal(): void {
    EntityTest::create(['name' => 'One'])->save();

    $component = $this->buildComponent();
    $shapes = $component->getPropShapesAll();

    $this->assertArrayHasKey('_aggregate~items', $shapes);
    $this->assertArrayHasKey('_aggregate~items~title', $shapes);
  }

}
