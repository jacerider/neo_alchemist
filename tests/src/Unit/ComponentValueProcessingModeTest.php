<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ComponentShapeValueInterface;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\PrefixValue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the site-builder-configurable "Processing" mode on value providers.
 *
 * This decides whether a provider claims the value — halting the pipeline — or
 * lets later providers run. ARCHITECTURE.md records a past regression in this
 * area where a `fallback` plugin ran ahead of a `providers` plugin and silently
 * erased a configured default, so the claim semantics are worth pinning down.
 */
#[Group('neo_alchemist')]
class ComponentValueProcessingModeTest extends UnitTestCase {

  /**
   * Builds a provider double in the state the pipeline hands it over in.
   *
   * The collection calls allowFurtherProcessing() on every instance it hands
   * out, so "not yet claimed" is the real starting state for each pass.
   * The reset matters because instances are memoised on the collection and
   * reused across the default, value and modify passes: without it, a claim
   * raised in one pass would silently truncate the next.
   *
   * @param string|null $mode
   *   The configured processing mode, or NULL to take the plugin default.
   * @param bool $providedValueIsEmpty
   *   What the shape reports for the value under test.
   *
   * @return \Drupal\Tests\neo_alchemist\Unit\TestProcessingModeProvider
   *   A provider reset to "not yet claimed".
   */
  private function provider(?string $mode, bool $providedValueIsEmpty): TestProcessingModeProvider {
    $shape = $this->createMock(ComponentShapeValueInterface::class);
    $shape->method('isProvidedValueEmpty')->willReturn($providedValueIsEmpty);

    $provider = new TestProcessingModeProvider();
    $provider->shape = $shape;
    if ($mode !== NULL) {
      $provider->configuration = ['processing_mode' => $mode];
    }
    $provider->allowFurtherProcessing();

    return $provider;
  }

  /**
   * The mode decides whether the provider claims the value.
   */
  #[DataProvider('processingModeProvider')]
  public function testApplyProcessingMode(?string $mode, bool $isEmpty, bool $expectClaimed, string $why): void {
    $provider = $this->provider($mode, $isEmpty);

    $provider->applyProcessingMode('any-value');

    $this->assertSame($expectClaimed, $provider->hasClaimedValue(), $why);
  }

  /**
   * Data for ::testApplyProcessingMode().
   */
  public static function processingModeProvider(): array {
    return [
      'stop_when_found + value found => claims' => [
        ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
        FALSE,
        TRUE,
        'A value was produced, so nothing later should overwrite it.',
      ],
      'stop_when_found + empty => lets the next provider try' => [
        ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
        TRUE,
        FALSE,
        'Nothing was produced, so the fallback must still get a turn.',
      ],
      'continue + value found => never claims' => [
        ComponentValueProcessingModeInterface::MODE_CONTINUE,
        FALSE,
        FALSE,
        'This mode exists precisely to allow later modification.',
      ],
      'continue + empty => never claims' => [
        ComponentValueProcessingModeInterface::MODE_CONTINUE,
        TRUE,
        FALSE,
        'Emptiness is irrelevant in continue mode.',
      ],
      'block + value found => claims' => [
        ComponentValueProcessingModeInterface::MODE_BLOCK,
        FALSE,
        TRUE,
        'Block always halts.',
      ],
      'block + empty => claims anyway' => [
        ComponentValueProcessingModeInterface::MODE_BLOCK,
        TRUE,
        TRUE,
        'Blocking on empty is the point of this mode — nothing renders.',
      ],
      'default mode behaves as stop_when_found (found)' => [
        NULL,
        FALSE,
        TRUE,
        'The unconfigured default is stop_when_found.',
      ],
      'default mode behaves as stop_when_found (empty)' => [
        NULL,
        TRUE,
        FALSE,
        'The unconfigured default is stop_when_found.',
      ],
    ];
  }

  /**
   * An explicit claim already raised is never undone.
   *
   * A veto (e.g. user_has_role denying access) claims the value directly. The
   * mode logic must respect that rather than re-deciding it.
   */
  public function testExistingClaimIsRespected(): void {
    $provider = $this->provider(ComponentValueProcessingModeInterface::MODE_CONTINUE, FALSE);
    $provider->claimValue();

    $provider->applyProcessingMode('any-value');

    $this->assertTrue(
      $provider->hasClaimedValue(),
      'Continue mode must not release a claim another code path raised.',
    );
  }

  /**
   * The default mode is stop_when_found.
   */
  public function testDefaultProcessingMode(): void {
    $provider = $this->provider(NULL, FALSE);
    $this->assertSame(
      ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
      $provider->getProcessingMode(),
    );
  }

  /**
   * A configured mode overrides the default.
   */
  public function testConfiguredModeOverridesDefault(): void {
    $provider = $this->provider(ComponentValueProcessingModeInterface::MODE_BLOCK, FALSE);
    $this->assertSame(
      ComponentValueProcessingModeInterface::MODE_BLOCK,
      $provider->getProcessingMode(),
    );
  }

  /**
   * The test double's claim bookkeeping matches the real base class.
   *
   * TestProcessingModeProvider reimplements the four claim methods instead of
   * extending ComponentValuePluginBase, which keeps this suite container-free
   * — but means every assertion above is only as trustworthy as that copy. If
   * the base class's bookkeeping ever changes, these tests would keep passing
   * against a fiction. This compares the two directly, so the double cannot
   * drift silently.
   *
   * The end-to-end behavior is separately covered through the real base class
   * in ComponentValueProcessingModeIntegrationTest.
   */
  public function testDoubleMatchesRealBaseClassBookkeeping(): void {
    $real = (new \ReflectionClass(PrefixValue::class))->newInstanceWithoutConstructor();
    $double = new TestProcessingModeProvider();

    // A fresh plugin has claimed nothing. (This assertion caught the double
    // shipping the inverse default while documenting it as a match — the
    // divergence was invisible because every test resets the flag first.)
    $this->assertFalse($real->hasClaimedValue(), 'Premise: a fresh plugin has not claimed.');
    $this->assertSame($real->hasClaimedValue(), $double->hasClaimedValue(), 'Fresh state matches.');
    $this->assertSame($real->shouldContinueProcessing(), $double->shouldContinueProcessing(), 'Fresh continue-flag matches.');

    $real->allowFurtherProcessing();
    $double->allowFurtherProcessing();
    $this->assertFalse($real->hasClaimedValue(), 'Premise: the reset leaves the claim clear.');
    $this->assertSame($real->hasClaimedValue(), $double->hasClaimedValue(), 'Post-reset state matches.');
    $this->assertSame($real->shouldContinueProcessing(), $double->shouldContinueProcessing(), 'Post-reset continue-flag matches.');

    $real->claimValue();
    $double->claimValue();
    $this->assertTrue($real->hasClaimedValue(), 'Premise: claiming sets the claim.');
    $this->assertSame($real->hasClaimedValue(), $double->hasClaimedValue(), 'Post-claim state matches.');
    $this->assertSame($real->shouldContinueProcessing(), $double->shouldContinueProcessing(), 'Post-claim continue-flag matches.');

    $real->allowFurtherProcessing()->stopFurtherProcessing();
    $double->allowFurtherProcessing()->stopFurtherProcessing();
    $this->assertSame($real->hasClaimedValue(), $double->hasClaimedValue(), 'stopFurtherProcessing() matches claimValue().');
  }

}
