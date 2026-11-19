<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Plugin\ComponentValue\FormattedTextValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hiding the text-format controls must not invent the controls it hides.
 *
 * The defect this pins the fix for: the callback wrote `#access` to four fixed
 * keys under the format wrapper, one of which — `help` — core's TextFormat
 * element no longer builds. Writing to a missing key CREATES an element, and an
 * element born in #after_build never passed through FormBuilder::doBuildForm(),
 * so it has no `#parents`. That is invisible until the form fails validation:
 * FormErrorHandler walks every element in the tree and FormState::getError()
 * reads `#parents` off each one, so one genuine error reached the editor as
 * three messages — the error itself, plus "Undefined array key #parents" and
 * "foreach() argument must be of type array|object, null given".
 *
 * So the subject is the invariant rather than the key: after this callback has
 * run, every element in the tree it returns still carries `#parents`. A test
 * that only asserted `help` was absent would pass again the day core drops
 * `guidelines`.
 *
 * The element shapes below are TextFormat's own output, trimmed to what this
 * callback touches.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\FormattedTextValue::afterBuild()
 * @see \Drupal\filter\Element\TextFormat::processFormat()
 * @see \Drupal\Core\Form\FormErrorHandler::setElementErrorsFromFormState()
 */
#[Group('neo_alchemist')]
class FormattedTextValueAfterBuildTest extends UnitTestCase {

  /**
   * A text_format element as core builds one today: no `help` child.
   */
  private function currentCoreElement(): array {
    return [
      0 => [
        '#parents' => ['description', 0],
        'value' => [
          '#type' => 'textarea',
          '#parents' => ['description', 0, 'value'],
        ],
        'format' => [
          '#type' => 'container',
          '#parents' => ['description', 0, 'format'],
          '#attributes' => ['class' => ['js-filter-wrapper']],
          'guidelines' => [
            '#type' => 'container',
            '#parents' => ['description', 0, 'format', 'guidelines'],
          ],
          'format' => [
            '#type' => 'select',
            '#parents' => ['description', 0, 'format'],
          ],
        ],
      ],
    ];
  }

  /**
   * Walks a form tree and collects the paths of elements with no `#parents`.
   *
   * This is the read FormErrorHandler performs, reduced to the one property
   * FormState::getError() needs.
   *
   * @param array $element
   *   The element to walk.
   * @param string[] $path
   *   The array path walked so far, for naming anything found.
   *
   * @return string[]
   *   The array paths of every descendant carrying no `#parents`.
   */
  private function parentlessPaths(array $element, array $path = []): array {
    $found = [];
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      if (!is_array($child)) {
        continue;
      }
      $childPath = array_merge($path, [(string) $key]);
      if (!isset($child['#parents'])) {
        $found[] = implode('][', $childPath);
      }
      $found = array_merge($found, $this->parentlessPaths($child, $childPath));
    }
    return $found;
  }

  /**
   * Nothing parentless survives the callback on the element core builds today.
   *
   * This is the regression itself: before the guard, `help` was conjured here
   * and this assertion named it.
   */
  public function testLeavesNoParentlessElementBehind(): void {
    $built = FormattedTextValue::afterBuild(
      $this->currentCoreElement(),
      $this->createMock(FormStateInterface::class),
    );

    $this->assertSame([], $this->parentlessPaths($built));
  }

  /**
   * The absent control is not resurrected as an empty, access-denied stub.
   *
   * Stated separately from the invariant above so a future reader can see what
   * the stray element actually was: a bare `['#access' => FALSE]` sitting in
   * the submitted form where core had put nothing at all.
   */
  public function testDoesNotCreateTheControlsCoreNoLongerBuilds(): void {
    $built = FormattedTextValue::afterBuild(
      $this->currentCoreElement(),
      $this->createMock(FormStateInterface::class),
    );

    $this->assertArrayNotHasKey('help', $built[0]['format']);
  }

  /**
   * The controls that do exist are still hidden — the guard is not a bypass.
   */
  public function testHidesEveryControlThatExists(): void {
    $built = FormattedTextValue::afterBuild(
      $this->currentCoreElement(),
      $this->createMock(FormStateInterface::class),
    );

    $this->assertFalse($built[0]['format']['guidelines']['#access']);
    $this->assertFalse($built[0]['format']['format']['#access']);
    $this->assertContains('hidden', $built[0]['format']['#attributes']['class']);
  }

  /**
   * A `help` child that is present is hidden, as it always was.
   *
   * Core has carried one before and a contrib text-format alter can add one
   * back, so the key stays in the list the callback walks; what changed is only
   * that it is written to when it is there.
   */
  public function testHidesHelpWhenSomethingHasBuiltIt(): void {
    $element = $this->currentCoreElement();
    $element[0]['format']['help'] = [
      '#type' => 'container',
      '#parents' => ['description', 0, 'format', 'help'],
    ];

    $built = FormattedTextValue::afterBuild(
      $element,
      $this->createMock(FormStateInterface::class),
    );

    $this->assertFalse($built[0]['format']['help']['#access']);
    $this->assertSame([], $this->parentlessPaths($built));
  }

  /**
   * No format wrapper means nothing to hide, and nothing to create either.
   *
   * A widget rendered without the text_format element — a plain textarea where
   * the format is fixed elsewhere — used to gain a whole `format` subtree here,
   * `#attributes` and all, parentless like the rest.
   */
  public function testLeavesWidgetWithNoFormatWrapperAlone(): void {
    $element = [
      0 => [
        '#parents' => ['description', 0],
        'value' => [
          '#type' => 'textarea',
          '#parents' => ['description', 0, 'value'],
        ],
      ],
    ];

    $built = FormattedTextValue::afterBuild(
      $element,
      $this->createMock(FormStateInterface::class),
    );

    $this->assertSame($element, $built);
  }

}
