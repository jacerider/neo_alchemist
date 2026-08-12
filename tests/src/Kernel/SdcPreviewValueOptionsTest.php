<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins that preview overrides carry per-prop options into the next build.
 *
 * The SDC preview workspace edits a transient neo_component that is never
 * saved: ComponentPreviewBuilder::build() only calls create(), and
 * SdcPreviewForm::save() returns 0. The single thread connecting one request to
 * the next is Component::setPreviewValues(), a cache entry keyed off the
 * builder's deterministic synthetic id.
 *
 * Prop *options* travelling through that entry is what makes media assignable
 * there at all. Clicking "Add media" has to turn the prop's "default" option
 * off, because the media widget is only built while that option is disabled
 * (ComponentShapePluginBase::getForm() — MediaValue::onShapeInit() forces the
 * default option's form on, so the render gate reduces to it being disabled).
 * If the flip does not survive into the rebuilt form, the widget is absent and
 * MediaValue::ajaxOverride() has nothing to open the media library with.
 *
 * That is why SdcPreviewForm::validateForm() persists on every submission
 * rather than only in the debounced refresh handler: any AJAX rebuild, not
 * just the refresh, has to see what the user just produced.
 *
 * Deliberately media-free. The behaviour under test is the option round trip,
 * and asserting it through a media prop would drag media, file, image and the
 * media library into a Kernel test to prove something about caching.
 *
 * @see \Drupal\neo_alchemist\Form\SdcPreviewForm::validateForm()
 * @see \Drupal\neo_alchemist\Entity\Component::setPreviewValues()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue::formAlter()
 */
#[Group('neo_alchemist')]
class SdcPreviewValueOptionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The SDC the preview workspace is built for.
   */
  private const SDC_ID = 'neo_alchemist_test:na_leaf';

  /**
   * The prop whose option is round-tripped.
   */
  private const PROP = 'text';

  /**
   * Builds the transient preview component the workspace edits.
   *
   * Goes through the service rather than hand-rolling the entity so the
   * synthetic id — the only link between the workspace request and the preview
   * iframe request — is the real one.
   */
  private function previewComponent() {
    $component = $this->container->get('neo_alchemist.preview_builder')->build(self::SDC_ID);
    $this->assertNotNull($component, 'The fixture SDC is Alchemist-enabled and previewable.');
    $this->assertTrue($component->isNew(), 'The preview component is transient.');
    $this->assertTrue($component->isPreview(), 'The preview flag is what routes getValues() to the overrides.');
    $this->assertSame('config', $component->getScope(), 'Preview overrides are only read in config scope.');
    return $component;
  }

  /**
   * A per-prop option written as a preview override reaches the next build.
   *
   * Asserts the premise before the behaviour: if the option did not start
   * enabled, or the prop were not editable, the flip below would prove nothing.
   */
  public function testPreviewOverridesCarryPerPropOptions(): void {
    $component = $this->previewComponent();

    $shape = $component->getPropShapes()[self::PROP] ?? NULL;
    $this->assertNotNull($shape, sprintf('The fixture exposes its "%s" prop.', self::PROP));
    $this->assertFalse(
      $shape->getOptionDefault()->isEnabled(),
      'Premise: the option starts off, so seeing it on below can only come from the override.',
    );

    $component->setPreviewValues([
      'props' => [
        self::PROP => [
          'ref' => $shape->getRef(),
          'value' => ['value' => 'OVERRIDDEN'],
          'options' => [$shape->id() => ['default' => 1]],
        ],
      ],
    ]);

    $this->assertTrue(
      $component->getPropShapes()[self::PROP]->getOptionDefault()->isEnabled(),
      'The option written as a preview override is reported by the rebuilt shape.',
    );
  }

  /**
   * Writing overrides drops the memoized shapes so the next read is fresh.
   *
   * The mechanism behind the test above, pinned separately because it is the
   * part that silently breaks: Component::getPropShapes() memoizes into
   * $this->propShapes, so without the unset() in setPreviewValues() the shapes
   * built before the write would be handed back unchanged for the rest of the
   * request — which is exactly the window an AJAX rebuild lives in.
   */
  public function testWritingOverridesInvalidatesMemoizedShapes(): void {
    $component = $this->previewComponent();

    // Memoize first, the way a form build does before validation runs.
    $before = $component->getPropShapes()[self::PROP];

    $component->setPreviewValues([
      'props' => [
        self::PROP => [
          'ref' => $before->getRef(),
          'value' => ['value' => 'OVERRIDDEN'],
          'options' => [$before->id() => ['default' => 1]],
        ],
      ],
    ]);

    $this->assertNotSame(
      $before,
      $component->getPropShapes()[self::PROP],
      'The memoized shape was discarded rather than reused.',
    );
  }

  /**
   * Resetting the workspace discards the overrides.
   *
   * The Reset button is the only route back once a prop has been overridden in
   * the workspace, so it has to actually clear rather than merely stop writing.
   */
  public function testResettingDiscardsOverrides(): void {
    $component = $this->previewComponent();
    $shape = $component->getPropShapes()[self::PROP];

    $component->setPreviewValues([
      'props' => [
        self::PROP => [
          'ref' => $shape->getRef(),
          'value' => ['value' => 'OVERRIDDEN'],
          'options' => [$shape->id() => ['default' => 1]],
        ],
      ],
    ]);
    $this->assertTrue($component->hasPreviewValues(), 'Premise: there is something to reset.');

    $component->resetPreviewValues();

    $this->assertFalse($component->hasPreviewValues());
    $this->assertFalse(
      $component->getPropShapes()[self::PROP]->getOptionDefault()->isEnabled(),
      'The option went back to its pre-override state.',
    );
  }

}
