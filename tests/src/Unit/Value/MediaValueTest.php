<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Shape\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeFieldItemInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeFormInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeIdentityInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeOption;
use Drupal\neo_alchemist\Shape\ComponentShapeOptionsInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue;
use Drupal\Tests\UnitTestCase;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;
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

  use ShapeDoubleTrait;

  /**
   * The roles a media shape answers through.
   *
   * MediaValue is one of the wide consumers — it names the prop, reads its
   * scope, rewires it onto a field item, swaps its widget, reads and writes
   * its options and asks what media it supports — so the double declares six
   * roles and the media capability rather than pretending to one. Each is
   * still narrow on its own, which is what makes a stub land on the role that
   * owns the method or not at all.
   *
   * The widget is a case in point. "Rewire the shape" reads as one act and is
   * two: the field type and its settings are the field-item role's, while the
   * widget belongs to the form role, because a widget is how the prop is
   * edited rather than how it is stored.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject[]
   *   The role doubles, keyed 'identity', 'context', 'value', 'options',
   *   'fieldItem', 'form' and 'media'.
   */
  private function mediaShapeRoles(): array {
    return [
      'identity' => $this->shapeRole(ComponentShapeIdentityInterface::class),
      'context' => $this->shapeRole(ComponentShapeContextInterface::class),
      'value' => $this->shapeRole(ComponentShapeValueInterface::class),
      'options' => $this->shapeRole(ComponentShapeOptionsInterface::class),
      'fieldItem' => $this->shapeRole(ComponentShapeFieldItemInterface::class),
      'form' => $this->shapeRole(ComponentShapeFormInterface::class),
      'media' => $this->shapeRole(ComponentShapeMediaPluginInterface::class),
    ];
  }

  /**
   * Assembles the roles into a shape a value provider will accept.
   *
   * @param \PHPUnit\Framework\MockObject\MockObject[] $roles
   *   The role doubles, as ::mediaShapeRoles() returns them.
   */
  private function mediaShape(array $roles): ComponentShapePluginInterface {
    return $this->shapeDouble(array_values($roles), [ComponentShapeMediaPluginInterface::class]);
  }

  /**
   * Builds the plugin against a media-capable shape.
   *
   * @param bool $defaultOptionEnabled
   *   What the shape's "default" option reports.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return array
   *   A tuple of [plugin, roles].
   */
  private function mediaPlugin(bool $defaultOptionEnabled, array $configuration = ['default' => []]): array {
    $option = $this->createMock(ComponentShapeOption::class);
    $option->method('isEnabled')->willReturn($defaultOptionEnabled);

    $roles = $this->mediaShapeRoles();
    $roles['options']->method('getOptionDefault')->willReturn($option);
    $roles['media']->method('getSupportedMediaTypes')->willReturn(['image', 'remote_video']);

    // No neo_config_file exists, so no default media hydrates.
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $plugin = new MediaValue('media', [], $this->mediaShape($roles), $configuration, $entityTypeManager);
    return [$plugin, $roles];
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
    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);
    $plugin = new MediaValue('media', [], $this->unusedShape(), ['default' => []], $entityTypeManager);

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

    $roles = $this->mediaShapeRoles();
    $roles['options']->method('getOptionDefault')->willReturn($option);
    $roles['media']->method('getSupportedMediaTypes')->willReturn(['image', 'remote_video']);

    // How it is stored is the field-item role's; how it is edited is the
    // form role's.
    $fieldItem = $roles['fieldItem'];
    $fieldItem->expects($this->once())->method('setFieldType')->with('entity_reference');
    $fieldItem->expects($this->once())->method('setFieldStorageSettings')->with(['target_type' => 'media']);
    $fieldItem->expects($this->once())->method('setFieldInstanceSettings')->with([
      'handler' => 'default:media',
      'handler_settings' => [
        'target_bundles' => ['image' => 'image', 'remote_video' => 'remote_video'],
      ],
    ]);
    $roles['form']->expects($this->once())->method('setWidget')->with('media_library_widget');

    $storage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    (new MediaValue('media', [], $this->mediaShape($roles), ['default' => []], $entityTypeManager))->onShapeInit();
  }

  /**
   * Shape init leaves a non-media shape alone.
   */
  public function testShapeInitIgnoresNonMediaShape(): void {
    $fieldItem = $this->shapeRole(ComponentShapeFieldItemInterface::class);
    $fieldItem->expects($this->never())->method('setFieldType');
    $form = $this->shapeRole(ComponentShapeFormInterface::class);
    $form->expects($this->never())->method('setWidget');
    $shape = $this->shapeDouble([$fieldItem, $form]);

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
   *   A tuple of [plugin, roles].
   */
  private function overridePlugin(string $shapeId, bool $defaultOptionEnabled = TRUE): array {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    [$plugin, $roles] = $this->mediaPlugin($defaultOptionEnabled);
    $roles['identity']->method('id')->willReturn($shapeId);
    $roles['identity']->method('getTitle')->willReturn('Image');
    $roles['context']->method('getScope')->willReturn('config');
    $roles['media']->method('getDefaultPreview')->willReturn(NULL);
    $roles['value']->method('getDefaultValue')->willReturn(NULL);

    return [$plugin, $roles];
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
    [$sibling, $siblingRoles] = $this->overridePlugin('items~image~1');
    $siblingRoles['options']->expects($this->never())->method('setOptions');

    [$clicked, $clickedRoles] = $this->overridePlugin('items~image~0');
    $clickedRoles['options']->method('getOptions')->willReturn(['default' => 1]);
    $clickedRoles['options']->expects($this->once())->method('setOptions')
      ->with(['default' => 0, 'empty' => 0]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#neo_override' => 'items~image~0']);

    $values = ['target_id' => 7];
    $sibling->massageValuesAlter($values, [], [], [], $formState);
    $clicked->massageValuesAlter($values, [], [], [], $formState);
  }

  /**
   * Pressing "Add media" on a hidden prop brings it out of hidden.
   *
   * The regression test. Only `default` used to be turned off, so the media
   * the author went on to pick landed in the widget and never reached the
   * page: the prop was still hidden, and the legend said so only if they
   * thought to look. The press is the one moment to decide it, because core's
   * update button replaces the form's #validate and so never reaches the
   * harvest that would.
   */
  public function testOverrideRevealsHiddenProp(): void {
    [$plugin, $roles] = $this->overridePlugin('image');
    $roles['options']->method('getOptions')->willReturn(['empty' => 1, 'default' => 1]);
    $roles['options']->expects($this->once())->method('setOptions')
      ->with(['empty' => 0, 'default' => 0]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#neo_override' => 'image']);

    $values = [];
    $plugin->massageValuesAlter($values, [], [], [], $formState);
  }

  /**
   * Builds the plugin over a config-hosted image shape.
   *
   * The `field` scope with an image media type is what diverts
   * massageValuesAlter() into the neo_config_file branch, where the override
   * button reads "Upload image" rather than "Add media".
   *
   * @param string $shapeId
   *   The id the shape reports.
   *
   * @return array
   *   A tuple of [plugin, roles].
   */
  private function configFilePlugin(string $shapeId): array {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getPluginId')->willReturn('image');
    $mediaType = $this->createMock(MediaTypeInterface::class);
    $mediaType->method('getSource')->willReturn($source);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['image' => $mediaType]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $roles = $this->mediaShapeRoles();
    $roles['identity']->method('id')->willReturn($shapeId);
    $roles['context']->method('getScope')->willReturn('field');
    $roles['media']->method('getSupportedMediaTypes')->willReturn(['image']);

    $plugin = new MediaValue('media', [], $this->mediaShape($roles), ['default' => []], $entityTypeManager);
    return [$plugin, $roles];
  }

  /**
   * Pressing "Upload image" on a hidden config-hosted prop reveals it too.
   *
   * The same rule on the neo_config_file branch, so a field default layout or
   * an Alchemist block does not keep the bug the entity form has lost.
   */
  public function testConfigFileOverrideRevealsHiddenProp(): void {
    [$plugin, $roles] = $this->configFilePlugin('image');
    $roles['options']->method('getOptions')->willReturn(['empty' => 1, 'default' => 1]);
    $roles['options']->expects($this->once())->method('setOptions')
      ->with(['empty' => 0, 'default' => 0]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#neo_override' => 'image']);

    $values = [];
    $plugin->massageValuesAlter($values, [], [], [], $formState);
  }

  /**
   * A stored file turns the default off but leaves a deliberate Hide alone.
   *
   * The default has always been turned off whenever a file is present, on
   * every submission. Un-hiding on the same condition would reverse a Hide
   * the next time anything else on the component changed, which is the shape
   * of the bug that made a hidden background image reappear on every edit.
   */
  public function testStoredConfigFileLeavesHideAlone(): void {
    [$plugin, $roles] = $this->configFilePlugin('image');
    $roles['options']->method('getOptions')->willReturn(['empty' => 1, 'default' => 1]);
    $roles['options']->expects($this->once())->method('setOptions')
      ->with(['empty' => 1, 'default' => 0]);

    // A refresh, not the override: nothing was pressed on this prop.
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'op']);

    $values = [];
    $plugin->massageValuesAlter($values, ['config_file' => 'stored-file'], [], [], $formState);
  }

  /**
   * An empty config-scope value is dropped rather than stored as an empty one.
   *
   * The NULL is deliberate. It is how the shape is told there is no value at
   * all, which switches the widget back to the default. It also travels on to
   * every other instance on this shape through the shared by-reference
   * argument, so those must tolerate it; the modifier that did not is what
   * turned an untouched image prop into a 500 on Save.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaImageSizeValue::massageValuesAlter()
   */
  public function testEmptyConfigScopeValueIsDropped(): void {
    [$plugin] = $this->overridePlugin('items~image~0');
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(NULL);

    $values = [];
    $plugin->massageValuesAlter($values, [], [], [], $formState);

    $this->assertNull($values, 'An image prop left empty stores nothing.');
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
