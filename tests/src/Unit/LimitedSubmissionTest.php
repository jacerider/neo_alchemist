<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element\Button;
use Drupal\Core\Render\Element\Submit;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one rule about reading #limit_validation_errors back.
 *
 * Drupal's Button element defaults the key to FALSE, meaning "do not limit".
 * Testing for the key's presence therefore classifies EVERY button — Save
 * included — as limited, and every commit path guarded by it stops running
 * while the form still reports success. That is a silent failure: the site
 * builder is told their change was saved.
 *
 * The rule was documented in exactly one of the nine files in this module that
 * set the key. It now lives in one trait, and this pins it there so a form
 * cannot inherit an inverted version of it.
 *
 * @see \Drupal\neo_alchemist\Form\LimitedSubmissionTrait
 */
#[Group('neo_alchemist')]
class LimitedSubmissionTest extends UnitTestCase {

  /**
   * The probe under test.
   */
  private LimitedSubmissionProbe $probe;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->probe = new LimitedSubmissionProbe();
  }

  /**
   * Runs the predicate against one triggering element.
   */
  private function ask(?array $trigger): bool {
    $formState = new FormState();
    if ($trigger !== NULL) {
      $formState->setTriggeringElement($trigger);
    }
    return $this->probe->ask($formState);
  }

  /**
   * The premise: core really does default the key to FALSE, not to absent.
   *
   * If this ever changes, the rule below changes with it — which is exactly
   * why the premise is asserted rather than assumed.
   */
  public function testCoreDefaultsTheKeyToFalse(): void {
    $this->assertFalse((new Button([], 'button', []))->getInfo()['#limit_validation_errors']);
    $this->assertFalse((new Submit([], 'submit', []))->getInfo()['#limit_validation_errors']);
  }

  /**
   * An ordinary button carries FALSE and is not a limited submission.
   */
  public function testDefaultingButtonIsNotLimited(): void {
    $this->assertFalse($this->ask(['#limit_validation_errors' => FALSE]), 'FALSE means "do not limit".');
  }

  /**
   * An array — including the empty one — is a limited submission.
   */
  public function testArrayIsLimited(): void {
    $this->assertTrue($this->ask(['#limit_validation_errors' => []]), 'Limited to nothing is still limited.');
    $this->assertTrue($this->ask(['#limit_validation_errors' => [['plugins', 'list']]]), 'Limited to a subtree is limited.');
  }

  /**
   * An element that never went through Button::getInfo() is not limited.
   */
  public function testAbsentKeyIsNotLimited(): void {
    $this->assertFalse($this->ask(['#type' => 'select']));
  }

  /**
   * No trigger at all — a first page build — is not a limited submission.
   */
  public function testNoTriggerIsNotLimited(): void {
    $this->assertFalse($this->ask(NULL));
  }

}
