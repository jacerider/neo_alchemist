<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Plugin\ComponentValue\ReadTimeValue;
use Drupal\neo_alchemist\Shape\ComponentShapeValueInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The read_time provider can claim the value it supplies, via the shared mode.
 *
 * The defect this pins the fix for: read_time sourced a value but implemented
 * no processing mode, so the provider search could never let it claim, and any
 * later provider on the same prop silently overwrote it. It now carries the
 * standard site-builder mode, defaulting to stop_when_found — and because its
 * placeholder is never empty, that default makes it claim.
 *
 * Tested at the plugin's own seam (getProcessingMode()/claimsValue()), which is
 * exactly what ValueProviderSearch consults; the search's own behaviour on that
 * answer is pinned by ValueProviderSearchTest and is not re-proven here.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ReadTimeValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
 */
#[Group('neo_alchemist')]
class ReadTimeValueTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Builds a read_time plugin whose shape answers emptiness truthfully.
   */
  private function plugin(): ReadTimeValue {
    $value = $this->shapeRole(ComponentShapeValueInterface::class);
    $value->method('isProvidedValueEmpty')->willReturnCallback(
      static fn (mixed $provided): bool => $provided === NULL || $provided === '',
    );
    return new ReadTimeValue('read_time', [], $this->shapeDouble([$value]), []);
  }

  /**
   * The out-of-the-box mode is stop_when_found, like any ordinary provider.
   */
  public function testDefaultsToStopWhenFound(): void {
    $this->assertSame(
      ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
      $this->plugin()->getProcessingMode(),
    );
  }

  /**
   * A produced placeholder claims, so a later provider cannot overwrite it.
   */
  public function testClaimsItsNonEmptyValue(): void {
    $this->assertTrue($this->plugin()->claimsValue('<span hidden data-neo-readtime></span>'));
  }

  /**
   * An empty value still falls through: the claim is conditional, not blanket.
   *
   * The provider never produces empty in practice, but this proves the mode is
   * the real stop_when_found rule rather than an unconditional claim — the
   * latter would starve the terminal Default on a prop that did come up empty.
   */
  public function testEmptyValueDoesNotClaim(): void {
    $this->assertFalse($this->plugin()->claimsValue(''));
  }

}
