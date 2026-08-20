<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\ChildShapeState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what a producer records about a shape's individual children.
 *
 * These records used to be three arrays and a plugin map behind nine shape
 * methods, seven of which were the same root-delegation branch. Nothing could
 * assert them without a component, a producer and a container. They are now one
 * object, and the properties that matter — a flag is three-valued, and an
 * absent flag is not FALSE — are pinned here.
 *
 * @see \Drupal\neo_alchemist\ChildShapeState
 */
#[Group('neo_alchemist')]
class ChildShapeStateTest extends UnitTestCase {

  /**
   * A flag that was never set reads as NULL, not FALSE.
   *
   * The distinction carries weight downstream: ChildOptionPolicy locks a child
   * option on TRUE or FALSE and leaves the child's own configuration standing
   * on NULL. Collapsing the two would let an unmapped child silently override
   * a setting the site builder made.
   */
  public function testAbsentFlagIsNullRatherThanFalse(): void {
    $state = new ChildShapeState();

    $this->assertNull($state->getFlag('root~child', ChildShapeState::HIDDEN));
  }

  /**
   * A flag set FALSE reads back as FALSE.
   */
  public function testFlagSetFalseReadsBackFalse(): void {
    $state = (new ChildShapeState())->setFlag('root~child', ChildShapeState::HIDDEN, FALSE);

    $this->assertFalse($state->getFlag('root~child', ChildShapeState::HIDDEN));
  }

  /**
   * Flags are per child and per flag name, with no bleed between them.
   */
  public function testFlagsDoNotBleedAcrossChildrenOrNames(): void {
    $state = (new ChildShapeState())
      ->setFlag('root~one', ChildShapeState::HIDDEN, TRUE)
      ->setFlag('root~two', ChildShapeState::USE_DEFAULT, TRUE);

    $this->assertTrue($state->getFlag('root~one', ChildShapeState::HIDDEN));
    $this->assertNull($state->getFlag('root~one', ChildShapeState::USE_DEFAULT));
    $this->assertNull($state->getFlag('root~two', ChildShapeState::HIDDEN));
    $this->assertTrue($state->getFlag('root~two', ChildShapeState::USE_DEFAULT));
  }

  /**
   * The last decision about a child wins.
   */
  public function testTheLastDecisionWins(): void {
    $state = (new ChildShapeState())
      ->setFlag('root~child', ChildShapeState::HIDDEN, TRUE)
      ->setFlag('root~child', ChildShapeState::HIDDEN, FALSE);

    $this->assertFalse($state->getFlag('root~child', ChildShapeState::HIDDEN));
  }

  /**
   * An enabled plugin is recorded with its settings.
   */
  public function testEnabledPluginCarriesItsSettings(): void {
    $state = (new ChildShapeState())->enablePlugin('root~child', 'media', ['bundle' => 'image']);

    $this->assertSame(
      ['media' => ['status' => TRUE, 'settings' => ['bundle' => 'image']]],
      $state->getPlugins('root~child'),
    );
  }

  /**
   * A disabled plugin is recorded rather than omitted.
   *
   * The shape building the child reads the entry to stop the plugin
   * initializing by default. An absent entry would let the default through,
   * which is the opposite of what the producer asked for.
   */
  public function testDisabledPluginIsRecordedNotOmitted(): void {
    $state = (new ChildShapeState())->disablePlugin('root~child', 'media');

    $plugins = $state->getPlugins('root~child');

    $this->assertArrayHasKey('media', $plugins);
    $this->assertFalse($plugins['media']['status']);
  }

  /**
   * A child nobody configured has no plugins.
   */
  public function testUnconfiguredChildHasNoPlugins(): void {
    $this->assertSame([], (new ChildShapeState())->getPlugins('root~child'));
  }

  /**
   * A decision recorded after the shape initialized is a mistake.
   *
   * This is the assertion ticket 02 dropped when the nine-method trait became
   * this object: hideChildShape(), defaultChildShape() and lockChildShape()
   * each asserted `!$this->isInitialized()` before writing. The writer is no
   * longer a shape method, so withdrawing it from an initialised shape's type
   * is not available — ChildOptionPolicy still reads the same accessor while
   * building children. Sealing puts the deadline back where the assertions had
   * it, and extends it to any writer added later.
   */
  public function testRecordingFlagAfterSealingFails(): void {
    $state = (new ChildShapeState())->forShape('gallery');
    $state->seal();

    $this->expectException(\AssertionError::class);

    $state->setFlag('gallery~image', ChildShapeState::HIDDEN);
  }

  /**
   * The plugin writers honour the seal too.
   */
  public function testEnablingPluginAfterSealingFails(): void {
    $state = (new ChildShapeState())->forShape('gallery');
    $state->seal();

    $this->expectException(\AssertionError::class);

    $state->enablePlugin('gallery~image', 'media');
  }

  /**
   * Disabling a plugin after the deadline is the same mistake.
   */
  public function testDisablingPluginAfterSealingFails(): void {
    $state = (new ChildShapeState())->forShape('gallery');
    $state->seal();

    $this->expectException(\AssertionError::class);

    $state->disablePlugin('gallery~image', 'media');
  }

  /**
   * Sealing one shape says nothing about any other.
   *
   * The deadline is per shape because initialization is. The state lives on the
   * root and every shape below it writes through the same store, so a whole-map
   * seal would stop an expanded child's own producer the moment the root
   * finished — and children initialize strictly after the root.
   */
  public function testSealingIsPerShape(): void {
    $state = new ChildShapeState();
    $state->forShape('gallery')->seal();

    $state->forShape('gallery~image')->setFlag('gallery~image~alt', ChildShapeState::HIDDEN);
    $state->forShape('other')->setFlag('other~title', ChildShapeState::USE_DEFAULT);

    $this->assertTrue($state->getFlag('gallery~image~alt', ChildShapeState::HIDDEN));
    $this->assertTrue($state->getFlag('other~title', ChildShapeState::USE_DEFAULT));
  }

  /**
   * Reading a sealed shape's decisions is what happens next, not a mistake.
   *
   * ChildOptionPolicy reads them as the children are built, which is precisely
   * the work the seal marks the start of.
   */
  public function testSealingDoesNotStopReads(): void {
    $state = (new ChildShapeState())->forShape('gallery');
    $state->setFlag('gallery~image', ChildShapeState::HIDDEN);
    $state->enablePlugin('gallery~image', 'media');
    $state->seal();

    $this->assertTrue($state->getFlag('gallery~image', ChildShapeState::HIDDEN));
    $this->assertArrayHasKey('media', $state->getPlugins('gallery~image'));
  }

  /**
   * A view writes into the store it was made from, not into a copy of it.
   */
  public function testViewsShareOneStore(): void {
    $state = new ChildShapeState();

    $state->forShape('gallery')->setFlag('gallery~image', ChildShapeState::HIDDEN);

    $this->assertTrue($state->getFlag('gallery~image', ChildShapeState::HIDDEN));
    $this->assertTrue($state->forShape('other')->getFlag('gallery~image', ChildShapeState::HIDDEN));
  }

}
