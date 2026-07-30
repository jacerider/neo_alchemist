<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\ComponentShapeOption;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `media` provider's empty-value contract and shape rewiring.
 *
 * Two behaviors here explain a lot of this suite's history:
 *
 * 1. When no media resolves and the "default" option is OFF,
 *    provideDefaultValue() returns [] — deliberately, so the image renders as
 *    NOTHING rather than falling back to the component's schema example. That
 *    is why the July 2026 delta-distribution bug showed up as 130 missing
 *    images rather than 130 placeholder images, and why the fixture
 *    TestProviderValue (a pass-through) degrades to the example instead.
 * 2. onShapeInit() rewrites the shape's field type to entity_reference and
 *    swaps in the media library widget. That rewiring is why testing through
 *    ImageShape would drag media/file/image into a Kernel test, and why the
 *    dependency-free TestProvidedShape twin exists at all.
 *
 * Both are exercised against a mocked media shape, which keeps the media
 * entity stack out of it. The hydration paths that need real media entities
 * and neo_config_file remain uncovered — see TESTING.md.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue
 * @see \Drupal\Tests\neo_alchemist\Kernel\ChildrenShapeDeltaDistributionTest
 */
#[Group('neo_alchemist')]
class MediaValueTest extends UnitTestCase {

  /**
   * Builds the plugin against a media-capable shape.
   *
   * @param bool $defaultOptionEnabled
   *   What the shape's "default" option reports.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return array
   *   A tuple of [plugin, shape].
   */
  private function mediaPlugin(bool $defaultOptionEnabled, array $configuration = ['default' => []]): array {
    $option = $this->createMock(ComponentShapeOption::class);
    $option->method('isEnabled')->willReturn($defaultOptionEnabled);

    $shape = $this->createMockForIntersectionOfInterfaces([
      ComponentShapePluginInterface::class,
      ComponentShapeMediaPluginInterface::class,
    ]);
    $shape->method('getOptionDefault')->willReturn($option);
    $shape->method('getSupportedMediaTypes')->willReturn(['image', 'remote_video']);

    // No neo_config_file exists, so no default media hydrates.
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $plugin = new MediaValue('media', [], $shape, $configuration, $entityTypeManager);
    return [$plugin, $shape];
  }

  /**
   * With no media and "default" off, the value collapses to nothing.
   *
   * This is the contract that makes a dropped image render as nothing.
   */
  public function testNoMediaWithDefaultOffReturnsEmpty(): void {
    [$plugin] = $this->mediaPlugin(FALSE);

    $this->assertSame([], $plugin->provideDefaultValue(['src' => 'EXAMPLE.png']), 'The schema example was discarded so nothing renders.');
  }

  /**
   * With no media but "default" on, the incoming value survives.
   *
   * The counterpart: leaving the option on is how an author opts into the
   * component's own example/placeholder image.
   */
  public function testNoMediaWithDefaultOnPassesValueThrough(): void {
    [$plugin] = $this->mediaPlugin(TRUE);

    $this->assertSame(
      ['src' => 'EXAMPLE.png'],
      $plugin->provideDefaultValue(['src' => 'EXAMPLE.png']),
      'With the default option on the example is allowed through.',
    );
  }

  /**
   * A configured default that resolves to no config file still collapses.
   *
   * Configuration pointing at a deleted neo_config_file must not accidentally
   * re-enable the example fallback.
   */
  public function testMissingConfigFileStillCollapses(): void {
    [$plugin] = $this->mediaPlugin(FALSE, ['default' => ['image' => 'gone']]);

    $this->assertSame([], $plugin->provideDefaultValue(['src' => 'EXAMPLE.png']));
  }

  /**
   * On a non-media shape the provider is a pass-through.
   *
   * The plugin is offered on image refs, but a shape that does not implement
   * the media interface must be left entirely alone.
   */
  public function testNonMediaShapeIsPassThrough(): void {
    $shape = $this->createMock(ComponentShapePluginInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);
    $plugin = new MediaValue('media', [], $shape, ['default' => []], $entityTypeManager);

    $this->assertSame('UNTOUCHED', $plugin->provideDefaultValue('UNTOUCHED'));
  }

  /**
   * Shape init rewires the shape onto an entity_reference media field.
   *
   * This is the rewiring the test suite's fixture shapes deliberately avoid:
   * the field type, storage settings, handler bundles and widget all change,
   * and the default option is forced visible.
   */
  public function testShapeInitRewiresTheShape(): void {
    $option = $this->createMock(ComponentShapeOption::class);
    $option->expects($this->once())
      ->method('alwaysShowForm')
      ->with(TRUE, $this->isType('string'));

    $shape = $this->createMockForIntersectionOfInterfaces([
      ComponentShapePluginInterface::class,
      ComponentShapeMediaPluginInterface::class,
    ]);
    $shape->method('getOptionDefault')->willReturn($option);
    $shape->method('getSupportedMediaTypes')->willReturn(['image', 'remote_video']);

    $shape->expects($this->once())->method('setFieldType')->with('entity_reference');
    $shape->expects($this->once())->method('setFieldStorageSettings')->with(['target_type' => 'media']);
    $shape->expects($this->once())->method('setFieldInstanceSettings')->with([
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => ['image' => 'image', 'remote_video' => 'remote_video'],
      ],
    ]);
    $shape->expects($this->once())->method('setWidget')->with('media_library_widget');

    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    (new MediaValue('media', [], $shape, ['default' => []], $entityTypeManager))->onShapeInit();
  }

  /**
   * Shape init leaves a non-media shape alone.
   */
  public function testShapeInitIgnoresNonMediaShape(): void {
    $shape = $this->createMock(ComponentShapePluginInterface::class);
    $shape->expects($this->never())->method('setFieldType');
    $shape->expects($this->never())->method('setWidget');

    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    (new MediaValue('media', [], $shape, ['default' => []], $entityTypeManager))->onShapeInit();
  }

}
