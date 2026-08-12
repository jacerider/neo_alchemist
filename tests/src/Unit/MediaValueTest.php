<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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

  /**
   * Builds the plugin over a config-scope media shape with a known id.
   *
   * The scope matters: 'field' would divert formAlter() and
   * massageValuesAlter() into the neo_config_file branch before reaching the
   * override button these tests are about.
   *
   * @param string $shapeId
   *   The id the shape reports, which the override button is stamped with.
   * @param bool $defaultOptionEnabled
   *   What the shape's "default" option reports. TRUE is the state that
   *   renders the override button rather than the media widget.
   *
   * @return array
   *   A tuple of [plugin, shape].
   */
  private function overridePlugin(string $shapeId, bool $defaultOptionEnabled = TRUE): array {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    [$plugin, $shape] = $this->mediaPlugin($defaultOptionEnabled);
    $shape->method('id')->willReturn($shapeId);
    $shape->method('getScope')->willReturn('config');
    $shape->method('getTitle')->willReturn('Image');
    $shape->method('getDefaultPreview')->willReturn(NULL);
    $shape->method('getDefaultValue')->willReturn(NULL);

    return [$plugin, $shape];
  }

  /**
   * The override button opts out of validating the rest of the form.
   *
   * Its whole job is to reveal the media widget, which only exists after a
   * rebuild. A validation error anywhere else on the component skips both the
   * submit handlers and the rebuild while the AJAX exception is still thrown,
   * so the callback would be handed a form with no widget in it — which is how
   * "Add media" came to raise a TypeError and reach the editor as a 500.
   *
   * @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
   */
  public function testOverrideButtonSkipsValidatingTheRestOfTheForm(): void {
    [$plugin] = $this->overridePlugin('items~image~0');
    $element = ['#id' => 'shape-form-values-items-0-image', '#type' => 'container'];

    $plugin->formAlter($element, $this->createMock(FormStateInterface::class));

    $this->assertArrayHasKey('override', $element, 'The default option being on renders the override button.');
    $this->assertSame([], $element['override']['#limit_validation_errors']);
    $this->assertSame([[MediaValue::class, 'submitOverride']], $element['override']['#submit']);
  }

  /**
   * The override button names the shape it belongs to.
   *
   * A bare TRUE cannot be told apart from another prop's button, which is what
   * ::testOverrideOnlyDisablesTheClickedShapesDefault() pins the effect of.
   */
  public function testOverrideButtonCarriesItsShapeId(): void {
    [$plugin] = $this->overridePlugin('items~image~2');
    $element = ['#id' => 'shape-form-values-items-2-image', '#type' => 'container'];

    $plugin->formAlter($element, $this->createMock(FormStateInterface::class));

    $this->assertSame('items~image~2', $element['override']['#neo_override']);
  }

  /**
   * Only the clicked prop's "default" option is turned off.
   *
   * The massage runs once for every media prop on the component while
   * getTriggeringElement() is form-global, so testing the marker for mere
   * truthiness turned the default off for all of them at once. The visible
   * symptom is that every *other* media prop stops rendering, because a prop
   * that is neither set nor defaulted resolves to nothing.
   */
  public function testOverrideOnlyDisablesTheClickedShapesDefault(): void {
    // A sibling prop on the same component: same click, different shape.
    [$sibling, $siblingShape] = $this->overridePlugin('items~image~1');
    $siblingShape->expects($this->never())->method('setOptions');

    [$clicked, $clickedShape] = $this->overridePlugin('items~image~0');
    $clickedShape->method('getOptions')->willReturn(['default' => 1]);
    $clickedShape->expects($this->once())->method('setOptions')->with(['default' => 0]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#neo_override' => 'items~image~0']);

    $values = ['target_id' => 7];
    $sibling->massageValuesAlter($values, [], [], [], $formState);
    $clicked->massageValuesAlter($values, [], [], [], $formState);
  }

  /**
   * Without a widget in the rebuilt form the callback degrades to a no-op.
   *
   * Belt and braces for the errors that #limit_validation_errors cannot
   * suppress: hand the element back rather than dereferencing a widget that
   * was never built, which is all ::ajaxConfigFileOverride() ever does.
   */
  public function testAjaxOverrideWithoutWidgetReturnsElement(): void {
    [$plugin] = $this->overridePlugin('items~image~0');

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn([
      '#array_parents' => ['values', 'image', 'override'],
    ]);
    $form = ['values' => ['image' => ['#id' => 'shape-form-values-image']]];

    $this->assertSame(
      $form['values']['image'],
      $plugin->ajaxOverride($form, $formState),
      'A form with no widget yields the element unchanged instead of a TypeError.',
    );
  }

}
