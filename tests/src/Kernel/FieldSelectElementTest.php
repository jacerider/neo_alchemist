<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\neo_alchemist_test\Form\FieldSelectTestForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * The neo_field_select element: what it renders, and what it accepts.
 *
 * Two of these pin regressions that failed in complete silence. A custom
 * `#type` gets no ajax at all unless it names its own event, because
 * RenderElementBase::preRenderAjaxForm switches on `#type` and its default arm
 * returns untouched; and ajax.js binds by `document.getElementById()`, so an
 * input rendered without an `id` has its ajax settings emitted and then wired
 * to nothing. In both cases the callback and wrapper sit in the render array
 * looking entirely correct, no error is raised, and the only symptom is a
 * dependent sub-form that never appears — which is easy to miss if you only
 * ever load a form whose value is already saved.
 *
 * The rest pin the security boundary. The control posts an opaque string, so
 * the offer list is re-derived server-side on submit; a check that only ran in
 * the browser would not enforce MatcherField::EXCLUDED_FIELD_TYPES.
 *
 * @see \Drupal\neo_alchemist\Element\FieldSelect
 */
#[Group('neo_alchemist')]
class FieldSelectElementTest extends FieldMatchKernelTestBase {

  /**
   * Builds the host form and returns the rendered element plus its structure.
   *
   * @param array $overrides
   *   Extra element properties.
   *
   * @return array
   *   ['html' => string, 'element' => array].
   */
  private function build(array $overrides = []): array {
    $formState = new FormState();
    $formState->set('element', $overrides);
    $form = $this->container->get('form_builder')
      ->buildForm(FieldSelectTestForm::class, $formState);
    $html = (string) $this->container->get('renderer')->renderRoot($form['field']);
    return ['html' => $html, 'element' => $form['field']];
  }

  /**
   * The input carries an id, because ajax.js binds behaviours by element id.
   */
  public function testInputRendersWithAnId(): void {
    ['html' => $html, 'element' => $element] = $this->build();

    $this->assertNotEmpty($element['#id'], 'The form builder assigned an id.');
    $this->assertStringContainsString('id="' . $element['#id'] . '"', $html, 'And it reached the rendered input.');
    $this->assertStringContainsString('name="field"', $html);
  }

  /**
   * A custom element type has to name its own ajax event.
   */
  public function testAjaxEventIsDefaulted(): void {
    $element = $this->build()['element'];

    $this->assertSame('change', $element['#ajax']['event'], 'Without this the element gets no ajax wiring at all.');
    $this->assertArrayHasKey('#attached', $element);
  }

  /**
   * An explicit event is left alone.
   */
  public function testExplicitAjaxEventIsNotOverridden(): void {
    $element = $this->build(['#ajax' => ['callback' => '::noop', 'event' => 'blur']])['element'];

    $this->assertSame('blur', $element['#ajax']['event']);
  }

  /**
   * The widget is handed both endpoints and the browser library.
   */
  public function testEndpointsAndLibraryAreAttached(): void {
    ['html' => $html, 'element' => $element] = $this->build();

    $this->assertContains('neo_alchemist/field-browser', $element['#attached']['library']);
    $search = $element['#attributes']['data-neo-field-search'];
    $browse = $element['#attributes']['data-neo-field-browse'];
    $this->assertStringContainsString('/field-match/na_heading/heading/heading~title', $search);
    $this->assertStringEndsWith('/browse', $browse);
    $this->assertStringContainsString('data-neo-field-search', $html);
  }

  /**
   * Overrides and unfiltered mode travel to the endpoint as query parameters.
   */
  public function testOverridesReachTheEndpointUrl(): void {
    $element = $this->build([
      '#all' => TRUE,
      '#entity_type' => 'entity_test',
      '#bundle' => 'entity_test',
    ])['element'];

    $browse = $element['#attributes']['data-neo-field-browse'];
    $this->assertStringContainsString('all=1', $browse);
    $this->assertStringContainsString('entity_type=entity_test', $browse);
    $this->assertStringContainsString('bundle=entity_test', $browse);
  }

  /**
   * A stored value ships with its human label, not just the raw key.
   *
   * Otherwise the control opens reading "roles._entity:label", which looks
   * like corrupted data rather than a saved setting.
   */
  public function testStoredValueShipsItsLabel(): void {
    $element = $this->build(['#default_value' => 'name'])['element'];

    $this->assertSame('name', $element['#value']);
    $this->assertStringContainsString('Name', $element['#attributes']['data-neo-field-label']);
    $this->assertSame('User', $element['#attributes']['data-neo-field-path']);
    $this->assertArrayNotHasKey('data-neo-field-missing', $element['#attributes']);
  }

  /**
   * A value that no longer matches renders flagged rather than blank.
   */
  public function testStaleValueIsFlaggedNotBlanked(): void {
    $element = $this->build(['#default_value' => 'field_that_went_away'])['element'];

    $this->assertSame('field_that_went_away', $element['#attributes']['data-neo-field-label'], 'The raw key still shows.');
    $this->assertSame('true', $element['#attributes']['data-neo-field-missing']);
  }

  /**
   * Synthetic options are shipped to the widget and labelled from config.
   */
  public function testExtraOptionsAreShippedAndLabelled(): void {
    $element = $this->build([
      '#extra' => ['_render' => 'Render with field formatter'],
      '#default_value' => '_render',
    ])['element'];

    $this->assertSame(
      '{"_render":"Render with field formatter"}',
      $element['#attributes']['data-neo-field-extra'],
    );
    $this->assertSame('Render with field formatter', $element['#attributes']['data-neo-field-label']);
    $this->assertArrayNotHasKey('data-neo-field-missing', $element['#attributes'], 'A synthetic value is not a stale field.');
  }

  /**
   * Submitting a value the shape does not offer is refused.
   *
   * @param string $value
   *   The posted value.
   * @param bool $valid
   *   Whether it should be accepted.
   * @param string $why
   *   Why.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('submittedValues')]
  public function testSubmittedValueIsCheckedServerSide(string $value, bool $valid, string $why): void {
    $formState = new FormState();
    $formState->setValues(['field' => $value]);
    $formState->setUserInput(['field' => $value]);
    $this->container->get('form_builder')->submitForm(FieldSelectTestForm::class, $formState);

    $errors = $formState->getErrors();
    $this->assertSame($valid, empty($errors), $why . ' (errors: ' . implode(', ', array_map('strval', $errors)) . ')');
  }

  /**
   * The values a submit may carry.
   *
   * @return array
   *   Cases of [value, accepted, why].
   */
  public static function submittedValues(): array {
    return [
      'a real match' => ['name', TRUE, 'A field the shape can take its value from.'],
      'a match across a reference hop' => ['roles._entity:label', TRUE, 'Reference paths are legal matches.'],
      'empty clears the binding' => ['', TRUE, 'Choosing nothing is allowed.'],
      'an excluded field type' => ['pass', FALSE, 'The password field must never validate.'],
      'a field that does not exist' => ['field_nope', FALSE, 'Unknown keys are refused.'],
      'a field the shape cannot hold' => ['roles', FALSE, 'A whole entity reference is not a string.'],
    ];
  }

  /**
   * A synthetic option is accepted even though it is not a field.
   */
  public function testExtraOptionValidates(): void {
    $formState = new FormState();
    $formState->set('element', ['#extra' => ['_render' => 'Render with field formatter']]);
    $formState->setValues(['field' => '_render']);
    $formState->setUserInput(['field' => '_render']);
    $this->container->get('form_builder')->submitForm(FieldSelectTestForm::class, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  /**
   * A synthetic option not declared by this element is still refused.
   */
  public function testUndeclaredExtraOptionIsRefused(): void {
    $formState = new FormState();
    $formState->setValues(['field' => '_render']);
    $formState->setUserInput(['field' => '_render']);
    $this->container->get('form_builder')->submitForm(FieldSelectTestForm::class, $formState);

    $this->assertNotSame([], $formState->getErrors(), 'Extras are per element, not a global allowlist.');
  }

}
