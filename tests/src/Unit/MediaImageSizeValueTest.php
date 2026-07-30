<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Drupal\neo_alchemist\Plugin\ComponentShape\StringShape;
use Drupal\neo_alchemist\Plugin\ComponentValue\MediaImageSizeValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `media_image_size` modifier, and the `size` sentinel it seeds.
 *
 * The plugin behaves two ways depending on where it is attached: on an image
 * prop it writes a `size` key into the value; on an image_size STYLE shape it
 * returns the dimensions directly.
 *
 * That `size` key is why the value-emptiness contract has a special case —
 * isProvidedValueEmpty() discounts it, because a value carrying only `size`
 * is a seeded placeholder rather than content. This class pins both the
 * mapping and that interaction.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaImageSizeValue
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::isProvidedValueEmpty()
 */
#[Group('neo_alchemist')]
class MediaImageSizeValueTest extends UnitTestCase {

  /**
   * A configured size list, shaped the way modifyValue() reads it.
   */
  private const SIZES = [
    'card' => [
      'label' => 'Card',
      'size' => ['dimensions' => ['sm' => ['width' => 640, 'height' => 480]]],
    ],
    'hero' => [
      'label' => 'Hero',
      'size' => ['dimensions' => ['sm' => ['width' => 1920, 'height' => '']]],
    ],
  ];

  /**
   * Builds the plugin on a plain (non-style) image-like shape.
   *
   * @param mixed $overrideValue
   *   What the shape reports as its stored override value.
   * @param array $sizes
   *   The configured size list.
   */
  private function plugin(mixed $overrideValue = NULL, array $sizes = self::SIZES): MediaImageSizeValue {
    $shape = $this->createMock(ComponentShapePluginInterface::class);
    $shape->method('getOverrideValue')->willReturn($overrideValue);
    return new MediaImageSizeValue('media_image_size', [], $shape, ['sizes' => $sizes]);
  }

  /**
   * Builds the plugin on an image_size style shape.
   *
   * ComponentShapeStylePluginInterface is a standalone marker — it does not
   * extend ComponentShapePluginInterface — so the shape has to satisfy both:
   * the plugin's constructor requires the latter and its isStyle() check
   * tests for the former.
   *
   * @param mixed $overrideValue
   *   What the shape reports as its stored override value.
   */
  private function stylePlugin(mixed $overrideValue = NULL): MediaImageSizeValue {
    $shape = $this->createMockForIntersectionOfInterfaces([
      ComponentShapePluginInterface::class,
      ComponentShapeStylePluginInterface::class,
    ]);
    $shape->method('getOverrideValue')->willReturn($overrideValue);
    return new MediaImageSizeValue('media_image_size', [], $shape, ['sizes' => self::SIZES]);
  }

  /**
   * On an image prop the default pass seeds the `size` key.
   */
  public function testDefaultPassSeedsSizeKey(): void {
    $value = $this->plugin()->provideDefaultValue([]);

    $this->assertSame(['size' => NULL], $value, 'An empty image value gains a seeded size key.');
  }

  /**
   * The seeded key carries the stored override's size when there is one.
   */
  public function testDefaultPassCarriesStoredSize(): void {
    $value = $this->plugin(['size' => 'hero'])->provideDefaultValue([]);

    $this->assertSame(['size' => 'hero'], $value);
  }

  /**
   * A style shape's default pass leaves the value alone.
   */
  public function testStyleShapeDefaultPassIsInert(): void {
    $this->assertSame([], $this->stylePlugin()->provideDefaultValue([]));
    $this->assertSame('lg', $this->stylePlugin()->provideDefaultValue('lg'));
  }

  /**
   * A selected size key is mapped to its dimensions.
   */
  public function testModifyMapsSizeKeyToDimensions(): void {
    $value = $this->plugin()->modifyValue(['src' => 'a.png', 'size' => 'hero']);

    $this->assertSame(['sm' => ['width' => 1920, 'height' => '']], $value['size']);
    $this->assertSame('a.png', $value['src'], 'The rest of the value is untouched.');
  }

  /**
   * With no size selected the first configured size applies.
   */
  public function testModifyFallsBackToFirstConfiguredSize(): void {
    $value = $this->plugin()->modifyValue(['src' => 'a.png', 'size' => NULL]);

    $this->assertSame(['sm' => ['width' => 640, 'height' => 480]], $value['size'], 'The first configured size is the default.');
  }

  /**
   * With no sizes configured at all the key is nulled.
   */
  public function testModifyNullsSizeWhenNoneConfigured(): void {
    $value = $this->plugin(NULL, [])->modifyValue(['src' => 'a.png', 'size' => 'hero']);

    $this->assertNull($value['size']);
    $this->assertSame('a.png', $value['src']);
  }

  /**
   * A style shape returns the dimensions themselves, not a wrapper.
   */
  public function testStyleShapeReturnsDimensions(): void {
    $value = $this->stylePlugin(['value' => 'hero'])->modifyValue('anything');

    $this->assertSame(['sm' => ['width' => 1920, 'height' => '']], $value);
  }

  /**
   * A size-only value counts as empty per the value contract.
   *
   * This is the sentinel rule's whole reason: the default pass above seeds
   * `size` into an otherwise empty image value, and the pipeline must still
   * treat that as "nothing was produced" so a fallback gets its turn.
   */
  public function testSeededSizeOnlyValueIsEmptyPerTheContract(): void {
    $seeded = $this->plugin()->provideDefaultValue([]);
    $shape = (new \ReflectionClass(StringShape::class))->newInstanceWithoutConstructor();

    $this->assertTrue($shape->isProvidedValueEmpty($seeded), 'A value carrying only the seeded size key is empty.');
    $this->assertFalse(
      $shape->isProvidedValueEmpty($seeded + ['src' => 'a.png']),
      'Once real content joins it, the value is no longer empty.',
    );
  }

}
