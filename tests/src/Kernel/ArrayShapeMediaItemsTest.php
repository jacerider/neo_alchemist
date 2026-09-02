<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape;
use Drupal\neo_alchemist\Plugin\ComponentShape\ImageShape;
use Drupal\neo_alchemist\Shape\ComponentShapeMediaPluginInterface;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins media-capable prop defs used as a bare array item type.
 *
 * `items: {type: image}` is the natural way to declare an array of images,
 * and it used to silently produce four loose scalar columns
 * (src/alt/width/height) instead of one ImageShape. No ImageShape meant the
 * `media` plugin it declares never attached, so the prop could not hold a
 * media entity reference at all — the picker was four raw text fields, and a
 * stored `target_id` resolved to nothing.
 *
 * The cause was that alterProp() inlines an object-typed def's own
 * sub-properties into `items.properties`, which made isSingleProp() read the
 * row as an ordinary multi-column object. It also records the def on
 * `items.ref`, and that is what isSingleProp() now keys off.
 *
 * Red/green proof performed during development: with `|| !empty($items['ref'])`
 * removed from ArrayShape::isSingleProp(), testRowResolvesToImageShape and
 * testMediaRowIsWiredForEntityReference go red — the row builds four scalar
 * children instead of one ImageShape, so nothing is left for the media plugin
 * to attach to.
 *
 * testExampleRowsCollapseToBareValues stays green either way, and that is the
 * point of keeping it: the pre-fix code reached the same bare
 * {src, alt, …} rows by never wrapping them, so it pins that the fix did not
 * change what twig sees. It is a no-regression guard, not a red test.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape::isSingleProp()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape::loadChildSchema()
 */
#[Group('neo_alchemist')]
class ArrayShapeMediaItemsTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    // na_array_nested's heading carries a `size` style prop, which is backed
    // by list_string.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   *
   * The media stack is required, not incidental: MediaValue::onShapeInit()
   * rewrites the shape's field into an entity_reference to `media`, so
   * without the entity type the shape cannot even be built.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image']);
  }

  /**
   * Loads a fixture component, freshly, so shape state is not memoised.
   */
  private function loadFixture(string $componentId): Component {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('neo_component');
    if (!$storage->load($componentId)) {
      Component::create([
        'id' => $componentId,
        'label' => 'Array media fixture',
        'description' => 'Array media fixture',
        'component' => 'neo_alchemist_test:' . $componentId,
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache([$componentId]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($componentId);
    return $component;
  }

  /**
   * The row is one ImageShape, not four scalar columns.
   */
  public function testRowResolvesToImageShape(): void {
    $component = $this->loadFixture('na_array_media');
    $shapes = $component->getPropShapes();
    $images = $shapes['images'];
    $this->assertInstanceOf(ArrayShape::class, $images);

    $this->assertTrue(
      $images->isSingleProp(),
      'A bare media prop def as the item type is a single-prop row.',
    );

    $children = $images->getChildShapes(0);
    $this->assertCount(1, $children, 'The row builds exactly one child.');

    $child = reset($children);
    $this->assertInstanceOf(ImageShape::class, $child);
    $this->assertInstanceOf(
      ComponentShapeMediaPluginInterface::class,
      $child,
      'Only a media shape can carry the media-library picker.',
    );
  }

  /**
   * The media plugin actually attached, so a target_id can be stored.
   *
   * MediaValue::onShapeInit() is the only thing that turns the field into an
   * entity_reference; asserting the field type is the cheapest proof that the
   * plugin ran rather than merely being applicable.
   */
  public function testMediaRowIsWiredForEntityReference(): void {
    $component = $this->loadFixture('na_array_media');
    $shapes = $component->getPropShapes();
    $images = $shapes['images'];
    $this->assertInstanceOf(ArrayShape::class, $images);

    $children = $images->getChildShapes(0);
    $child = reset($children);

    $this->assertSame('entity_reference', $child->getFieldType());
  }

  /**
   * Schema examples reach twig as bare rows, not wrapped in `value`.
   *
   * The fixture twig writes `img.src`. If the synthesized wrapper survived,
   * every template consuming an array of images would need `img.value.src`.
   */
  public function testExampleRowsCollapseToBareValues(): void {
    $component = $this->loadFixture('na_array_media');
    $component->setPreview(TRUE);
    $images = $component->getPropValues()['images'] ?? [];

    $this->assertCount(2, $images);
    foreach ($images as $delta => $row) {
      $this->assertIsArray($row);
      $this->assertArrayNotHasKey(
        'value',
        $row,
        "Row $delta kept the synthesized wrapper.",
      );
      $this->assertArrayHasKey('src', $row);
      $this->assertSame("EXAMPLE ALT $delta", $row['alt']);
    }
  }

  /**
   * An author-written object row keeps its multi-column behaviour.
   *
   * `items: {type: object, properties: …}` carries no `ref`, so widening
   * isSingleProp() must not swallow the canonical nested form.
   */
  public function testObjectRowsAreNotSingleProp(): void {
    $component = $this->loadFixture('na_array_nested');
    $shapes = $component->getPropShapes();
    $items = $shapes['items'];
    $this->assertInstanceOf(ArrayShape::class, $items);

    $this->assertFalse(
      $items->isSingleProp(),
      'An explicit object row is not a single-prop array.',
    );
    $this->assertGreaterThan(1, count($items->getChildShapes(0)));
  }

}
