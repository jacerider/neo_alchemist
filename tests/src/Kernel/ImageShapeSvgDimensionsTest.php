<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\entity_test\Entity\EntityTestWithBundle;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * An SVG image prop carries its own dimensions, never the schema example's.
 *
 * No image toolkit core ships can read an SVG, so an image field stores no
 * width or height for one and the media source reports NULL for both. The
 * image value kept those NULLs, and the object shape then seeded each child
 * from `$value[$name] ?? $examples[$name]` — which reads an explicit NULL as
 * a missing key. The `width` and `height` children fell through to the
 * component author's placeholder numbers, so a site whose hero wordmark was
 * an SVG rendered `<img width="2006" height="372">` over a 400x120 logo.
 *
 * Two changes, pinned separately:
 *
 * - the image shape reads an SVG's dimensions from its root element — the
 *   `width` and `height` attributes when both are absolute, the `viewBox`
 *   otherwise — so the template gets the real aspect ratio;
 * - a media value's explicit NULL is an answer, not a gap: when nothing can
 *   be measured the keys are dropped rather than backfilled from examples.
 *
 * A raster is the control: its stored dimensions pass through unchanged.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ImageShape::getValueFromMedia()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\MediaShapeBase::loadChildSchema()
 */
#[Group('neo_alchemist')]
class ImageShapeSvgDimensionsTest extends KernelTestBase {

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
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The image media type's source field name.
   */
  protected string $sourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['image']);

    $mediaType = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $mediaType->save();
    $sourceField = $mediaType->getSource()->createSourceField($mediaType);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $mediaType->set('source_configuration', ['source_field' => $sourceField->getName()])->save();
    $this->sourceField = $sourceField->getName();

    EntityTestBundle::create(['id' => 'main', 'label' => 'Main'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test_with_bundle',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'entity_test_with_bundle',
      'bundle' => 'main',
      'label' => 'Media',
    ])->save();
  }

  /**
   * An SVG with only a viewBox takes its dimensions from the viewBox.
   */
  public function testViewBoxSuppliesTheDimensions(): void {
    $image = $this->renderSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 120"></svg>');
    $this->assertSame(400, $image['width'] ?? NULL);
    $this->assertSame(120, $image['height'] ?? NULL);
  }

  /**
   * Absolute width and height attributes win over the viewBox.
   */
  public function testAbsoluteAttributesWin(): void {
    $image = $this->renderSvg('<svg xmlns="http://www.w3.org/2000/svg" width="200px" height="60" viewBox="0 0 400 120"></svg>');
    $this->assertSame(200, $image['width'] ?? NULL);
    $this->assertSame(60, $image['height'] ?? NULL);
  }

  /**
   * A relative attribute defers to the viewBox, keeping its aspect ratio.
   *
   * `width="100%"` says nothing about the image's intrinsic size, so both
   * dimensions come from the viewBox rather than one from each.
   */
  public function testRelativeAttributesDeferToTheViewBox(): void {
    $image = $this->renderSvg('<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 300 90"></svg>');
    $this->assertSame(300, $image['width'] ?? NULL);
    $this->assertSame(90, $image['height'] ?? NULL);
  }

  /**
   * One absolute attribute plus a viewBox derives the other dimension.
   */
  public function testOneAttributeScalesTheViewBox(): void {
    $image = $this->renderSvg('<svg xmlns="http://www.w3.org/2000/svg" width="200" viewBox="0 0 400 120"></svg>');
    $this->assertSame(200, $image['width'] ?? NULL);
    $this->assertSame(60, $image['height'] ?? NULL);
  }

  /**
   * An unmeasurable SVG drops the keys instead of taking the example's.
   *
   * The fixture's example is 100x50. Before the fix this value came back
   * carrying exactly that, over an image of unknown size.
   */
  public function testUnmeasurableSvgTakesNoExampleDimensions(): void {
    foreach ([
      'no size at all' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
      'not xml' => 'this is not an svg',
    ] as $case => $svg) {
      $image = $this->renderSvg($svg);
      $this->assertStringContainsString('.svg', $image['src'] ?? '', $case . ': the SVG is the image.');
      $this->assertArrayNotHasKey('width', $image, $case . ': no example width.');
      $this->assertArrayNotHasKey('height', $image, $case . ': no example height.');
    }
  }

  /**
   * A raster's stored dimensions pass through unchanged.
   */
  public function testRasterKeepsItsStoredDimensions(): void {
    \Drupal::service('file_system')->copy(\Drupal::root() . '/core/tests/fixtures/files/image-test.png', 'public://raster.png');
    $image = $this->renderFile('public://raster.png');
    $this->assertSame(40, $image['width'] ?? NULL);
    $this->assertSame(20, $image['height'] ?? NULL);
  }

  /**
   * Renders the probe component's image prop bound to an SVG.
   *
   * @param string $svg
   *   The SVG markup to write.
   *
   * @return array
   *   The image prop value.
   */
  private function renderSvg(string $svg): array {
    $uri = 'public://probe-' . uniqid() . '.svg';
    \file_put_contents($uri, $svg);
    return $this->renderFile($uri);
  }

  /**
   * Renders the probe component's image prop bound to a file.
   *
   * @param string $uri
   *   The file's URI.
   *
   * @return array
   *   The image prop value.
   */
  private function renderFile(string $uri): array {
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Probe',
      $this->sourceField => [
        'target_id' => $file->id(),
        'alt' => 'Probe alt',
      ],
    ]);
    $media->save();

    $component = $this->buildComponent();
    $host = EntityTestWithBundle::create([
      'type' => 'main',
      'name' => 'HOST',
      'field_media' => [$media->id()],
    ]);
    $host->save();
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()));
    return $component->getPropValues()['image'] ?? [];
  }

  /**
   * A probe component whose image prop is bound to the host's media field.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'SVG dimensions fixture',
      'description' => 'SVG dimensions fixture',
      'component' => 'neo_alchemist_test:na_image_probe',
      'status' => TRUE,
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    $id = $component->id();
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.image.plugins.image', [
        'entity' => [
          'id' => 'entity',
          'settings' => [
            'field' => 'field_media',
            'processing_mode' => 'block',
          ],
        ],
        'media' => [
          'id' => 'media',
          'settings' => ['default' => []],
        ],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

}
