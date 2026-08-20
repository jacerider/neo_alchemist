<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Shape;

use Drupal\neo_alchemist\Shape\ComponentShapeOption;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the option value/access/locked precedence rules.
 *
 * Every shape carries three of these (`default`, `empty`, `access`), and they
 * decide whether a prop renders its authored value, falls back to the schema
 * example, or is hidden outright. A parent shape locks its children's options
 * during initialisation, so a precedence mistake here shows up as content
 * silently changing — not as an error.
 */
#[Group('neo_alchemist')]
class ComponentShapeOptionTest extends UnitTestCase {

  /**
   * With nothing locked, the set value wins over the constructor default.
   */
  public function testSetValueOverridesDefault(): void {
    $option = new ComponentShapeOption(FALSE, TRUE);
    $this->assertFalse($option->isEnabled(), 'Falls back to the default value.');

    $option->setValue(TRUE);
    $this->assertTrue($option->isEnabled());
    $this->assertTrue($option->isDisabled() === FALSE, 'isDisabled() is the inverse of isEnabled().');

    $option->setValue(FALSE);
    $this->assertTrue($option->isDisabled());
  }

  /**
   * A locked value outranks both the set value and the default.
   */
  #[DataProvider('lockedPrecedenceProvider')]
  public function testLockedValueOutranksEverything(bool $default, ?bool $set, bool $locked, bool $expected): void {
    $option = new ComponentShapeOption($default, TRUE);
    if ($set !== NULL) {
      $option->setValue($set);
    }
    $option->setLockedValue($locked);

    $this->assertSame($expected, $option->isEnabled());
    $this->assertSame($locked, $option->isLocked());
  }

  /**
   * Data for ::testLockedValueOutranksEverything().
   */
  public static function lockedPrecedenceProvider(): array {
    return [
      'locked TRUE beats default FALSE' => [FALSE, NULL, TRUE, TRUE],
      'locked FALSE beats default TRUE' => [TRUE, NULL, FALSE, FALSE],
      'locked TRUE beats set FALSE' => [FALSE, FALSE, TRUE, TRUE],
      'locked FALSE beats set TRUE' => [TRUE, TRUE, FALSE, FALSE],
    ];
  }

  /**
   * Locking is first-write-wins, so the outermost parent keeps control.
   *
   * ChildrenShapeBase::initChildShape() pushes locked values down the shape
   * tree. If a later call could overwrite an earlier lock, a nested child
   * could escape the constraint its ancestor imposed.
   */
  public function testSetLockedValueIsFirstWriteWins(): void {
    $option = new ComponentShapeOption(FALSE, TRUE);
    $option->setLockedValue(TRUE);
    $option->setLockedValue(FALSE);

    $this->assertTrue($option->isLocked(), 'The second lock is ignored.');
    $this->assertTrue($option->isEnabled());
  }

  /**
   * Locking to FALSE first also wins — the guard is isset(), not truthiness.
   */
  public function testFirstWriteWinsWhenLockingToFalse(): void {
    $option = new ComponentShapeOption(TRUE, TRUE);
    $option->setLockedValue(FALSE);
    $option->setLockedValue(TRUE);

    $this->assertFalse($option->isLocked());
    $this->assertFalse($option->isEnabled());
  }

  /**
   * An unlocked option reports NULL, not FALSE.
   *
   * IsEnabled() branches on `!is_null($locked)`, so conflating "not locked"
   * with "locked to FALSE" would permanently disable the option.
   */
  public function testUnlockedReportsNull(): void {
    $option = new ComponentShapeOption(TRUE, TRUE);
    $this->assertNull($option->isLocked());
    $this->assertTrue($option->isEnabled(), 'Falls through to the value.');
  }

  /**
   * A constructor-supplied locked value applies without any setter call.
   */
  public function testConstructorLockedValueApplies(): void {
    $option = new ComponentShapeOption(TRUE, TRUE, FALSE);
    $this->assertFalse($option->isLocked());
    $this->assertFalse($option->isEnabled(), 'The constructor lock wins over the value.');

    // An explicit lock still takes precedence over the constructor default.
    $option->setLockedValue(TRUE);
    $this->assertTrue($option->isLocked());
    $this->assertTrue($option->isEnabled());
  }

  /**
   * Access is tracked independently of value.
   */
  public function testAccessIsIndependentOfValue(): void {
    $option = new ComponentShapeOption(TRUE, FALSE);
    $this->assertFalse($option->isAllowed(), 'Falls back to the default access.');
    $this->assertTrue($option->isEnabled(), 'Access does not affect the value.');

    $option->setAccess(TRUE);
    $this->assertTrue($option->isAllowed());
    $this->assertTrue($option->isEnabled());

    // Unlike locking, access is last-write-wins.
    $option->setAccess(FALSE);
    $this->assertFalse($option->isAllowed());
  }

  /**
   * Forcing the form on also forces access, and is reported separately.
   */
  public function testAlwaysShowFormForcesAccess(): void {
    $option = new ComponentShapeOption(FALSE, FALSE);
    $this->assertFalse($option->isAllowed());
    $this->assertFalse($option->isFormForced());

    $option->alwaysShowForm();

    $this->assertTrue($option->isFormForced());
    $this->assertTrue($option->isAllowed(), 'alwaysShowForm() implies access.');
    $this->assertFalse($option->isEnabled(), 'But it does not change the value.');
  }

  /**
   * AlwaysShowForm(FALSE) still grants access — it only clears the flag.
   *
   * Characterising a genuine asymmetry: the method unconditionally calls
   * setAccess(TRUE) regardless of the argument.
   */
  public function testAlwaysShowFormFalseStillGrantsAccess(): void {
    $option = new ComponentShapeOption(FALSE, FALSE);
    $option->alwaysShowForm(FALSE);

    $this->assertFalse($option->isFormForced());
    $this->assertTrue($option->isAllowed());
  }

  /**
   * Setters are chainable.
   */
  public function testFluentInterface(): void {
    $option = new ComponentShapeOption(FALSE, FALSE);
    $result = $option->setValue(TRUE)->setAccess(TRUE)->alwaysShowForm()->setLockedValue(TRUE);

    $this->assertSame($option, $result);
  }

  /**
   * The log records state transitions for debugging locked-value conflicts.
   */
  public function testLogRecordsTransitions(): void {
    $option = new ComponentShapeOption(FALSE, TRUE);
    $this->assertCount(1, $option->getLog(), 'Construction is logged.');
    $this->assertStringContainsString('Initialized', $option->getLog()[0]);
    $this->assertStringContainsString('Value DEFAULT 0', $option->getLog()[0]);
    $this->assertStringContainsString('Access DEFAULT 1', $option->getLog()[0]);

    // A setter without a message is silent.
    $option->setValue(TRUE);
    $this->assertCount(1, $option->getLog());

    $option->setValue(TRUE, 'Parent enabled it.');
    $log = $option->getLog();
    $this->assertCount(2, $log);
    $this->assertStringContainsString('Parent enabled it.', $log[1]);
    $this->assertStringContainsString('Value 1', $log[1], 'No longer reported as a default.');

    $option->setLockedValue(TRUE, 'Locked by parent.');
    $log = $option->getLog();
    $this->assertCount(3, $log);
    $this->assertStringContainsString('setLockedValue: Locked by parent.', $log[2]);
    $this->assertStringContainsString('LOCKED TRUE', $log[2]);
  }

  /**
   * A rejected second lock leaves no misleading log entry.
   */
  public function testRejectedLockIsNotLogged(): void {
    $option = new ComponentShapeOption(FALSE, TRUE);
    $option->setLockedValue(TRUE, 'First.');
    $countAfterFirst = count($option->getLog());

    $option->setLockedValue(FALSE, 'Second, ignored.');

    $this->assertCount($countAfterFirst, $option->getLog());
  }

}
