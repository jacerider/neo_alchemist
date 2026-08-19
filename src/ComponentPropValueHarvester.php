<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;

/**
 * Harvests submitted prop values out of a component value editor's form.
 *
 * Two forms edit a component's prop values: InstanceComponentForm, used when a
 * component is placed on a page, and SdcPreviewForm, used in the component
 * workspace. They differ only in where the harvested values go — stored values
 * on the instance, cache-backed preview overrides on the transient entity — so
 * everything before that point lives here: the access gate, the per-prop
 * subform state, the shape's own validation, the scalar guard, the massage
 * step, the union rule, the scalar restore and the nested options.
 *
 * None of those rules are obvious, and each was learned the hard way. They used
 * to be stated in a comment in InstanceComponentForm and pointed at from
 * SdcPreviewForm with "see the other form's validate method" — a seam
 * implemented as a comment, with nothing stopping the two copies from drifting
 * and nothing making the rules apply to a third editor. Stated here, a third
 * value editor inherits them by calling ::harvest() rather than by
 * rediscovering them.
 *
 * @see \Drupal\neo_alchemist\Form\InstanceComponentForm::validateForm()
 * @see \Drupal\neo_alchemist\Form\SdcPreviewForm::validateForm()
 * @see \Drupal\neo_alchemist\ComponentValuePanelBuilder
 *   Builds the form this reads back; the two are deliberately separate, since
 *   building a form and harvesting its values are different jobs.
 */
final class ComponentPropValueHarvester {

  /**
   * Harvests a component's submitted prop values.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being edited.
   * @param array $form
   *   The complete value editor form. Prop forms are read from its `values`
   *   element, which is where ComponentValuePanelBuilder puts them.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the submission.
   * @param array $original_values
   *   The values the editor opened on, as captured into form state on the
   *   first build. Keyed the way a component's values are: `props`, then prop
   *   name, then `value`.
   *
   * @return array
   *   The props structure, keyed by prop name, each carrying `ref`, `value`
   *   and `options`. Empty when nothing was harvested — callers should leave
   *   their `props` key unset in that case rather than writing an empty array,
   *   because the two are not the same thing to either sink.
   */
  public function harvest(ComponentInterface $component, array $form, FormStateInterface $form_state, array $original_values): array {
    $props = [];
    foreach ($component->getPropShapes() as $propName => $shape) {
      // Both editors build a prop's form only when the account may update it,
      // so a prop missing from the form was already gated out at build time.
      // Asking the shape again keeps the gate with the harvest rather than
      // leaving it as an inference about how the form happened to be built.
      if (!$shape->access('update') || !isset($form['values'][$propName])) {
        continue;
      }
      $subform_state = SubformState::createForSubform($form['values'][$propName], $form, $form_state);
      $originalValue = $original_values['props'][$propName]['value'] ?? [];
      // A prop stored as a scalar (markup, string, number) cannot travel
      // through massageFormValues(), which both takes and returns an array —
      // passing one fatals, and every validation of these forms runs through
      // here, so Save is broken too. Keep it out of that path rather than
      // wrapping it: wrapping would survive the call but then merge a stray
      // numeric key into the stored value via the union below.
      $originalArray = is_array($originalValue) ? $originalValue : [];
      $shape->validateForm($form['values'][$propName], $subform_state);
      $value = $subform_state->getValues();
      $props[$propName]['ref'] = $shape->getRef();
      $props[$propName]['value'] = $shape->massageFormValues($value, $originalArray, $form['values'][$propName], $subform_state);
      if (!$shape->isIterable() && !empty($props[$propName]['value'])) {
        $props[$propName]['value'] += $originalArray;
      }
      if (!is_array($originalValue) && $shape->getOptionDefault()->isEnabled()) {
        // Restoring the previous value is the one thing the original is
        // threaded through for, so hand the scalar back directly — the array
        // return type above cannot carry it.
        $props[$propName]['value'] = $originalValue;
      }
      $props[$propName]['options'] = $shape->getNestedOptionMap()->toArray();
    }
    return $props;
  }

}
