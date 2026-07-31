<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Element;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\FieldMatchLocator;

/**
 * A searchable picker for an entity field to source a prop value from.
 *
 * Replaces the group-then-field pair of selects the value plugins have each
 * grown their own copy of. Those exist because a shape's match list runs to
 * well over a thousand entries once referenced entities are walked, and
 * rendering that into a `<select>` is a six-figure byte payload per widget.
 * Splitting it in two shrinks the payload but costs the thing people actually
 * want: searching by field name when you do not already know which reference
 * path reaches it.
 *
 * This element renders no options at all. It is a hidden `<input>` carrying the
 * raw matcher key, which neo_alchemist/field-browser upgrades into a trigger
 * plus a Miller-column modal: one column per reference hop, fed by ::route(),
 * with search taking over the panel while you type.
 *
 * Usage:
 * @code
 * $form['field'] = [
 *   '#type' => 'neo_field_select',
 *   '#title' => $this->t('Field'),
 *   '#component' => $component->id(),
 *   '#prop' => 'heading',
 *   '#shape' => 'heading~title',
 *   '#default_value' => 'field_subtitle',
 * ];
 * @endcode
 *
 * @see \Drupal\neo_alchemist\Controller\FieldMatchController
 * @see \Drupal\neo_alchemist\FieldMatchLocator
 */
#[FormElement('neo_field_select')]
final class FieldSelect extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    $class = static::class;
    return [
      '#input' => TRUE,
      '#component' => NULL,
      '#prop' => NULL,
      '#shape' => FieldMatchLocator::ROOT,
      // Offer every field rather than only those the shape can accept.
      '#all' => FALSE,
      // Match against this entity type/bundle rather than the component's own
      // target. An entity_query provider sorts by fields of the type it
      // queries, which is not the type the component is attached to.
      '#entity_type' => NULL,
      '#bundle' => NULL,
      // Synthetic choices that are not entity fields — "Render with field
      // formatter", "Use Default", a raw literal. They are pinned above the
      // browsable tree and are legal values, so validation must accept them.
      // Keyed value => label.
      '#extra' => [],
      '#empty_option' => NULL,
      '#process' => [
        [$class, 'processFieldSelect'],
        [$class, 'processAjaxForm'],
      ],
      '#element_validate' => [
        [$class, 'validateFieldSelect'],
      ],
      '#pre_render' => [
        [$class, 'preRenderFieldSelect'],
      ],
      '#theme' => 'input__textfield',
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input === FALSE) {
      return isset($element['#default_value']) ? (string) $element['#default_value'] : '';
    }
    return is_scalar($input) ? (string) $input : '';
  }

  /**
   * Attaches the search endpoint and the current value's label.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The processed element.
   */
  public static function processFieldSelect(&$element, FormStateInterface $form_state, &$complete_form): array {
    // RenderElementBase::preRenderAjaxForm switches on #type to pick a default
    // event, and its default arm returns without attaching anything at all.
    // A custom #type therefore gets NO ajax unless the event is named here —
    // silently, with the callback and wrapper still sitting in the array
    // looking correct. The widget dispatches `change` on commit and on clear.
    if (!empty($element['#ajax']) && empty($element['#ajax']['event'])) {
      $element['#ajax']['event'] = 'change';
    }
    $element['#attributes']['type'] = 'text';
    $element['#attributes']['class'][] = 'neo-field-select';
    $element['#attributes']['autocomplete'] = 'off';
    $element['#attributes']['data-neo-field-search'] = static::route($element, 'neo_alchemist.field_match_search')->toString();
    $element['#attributes']['data-neo-field-browse'] = static::route($element, 'neo_alchemist.field_match_browse')->toString();
    if (!empty($element['#empty_option'])) {
      $element['#attributes']['data-neo-field-empty'] = (string) $element['#empty_option'];
    }
    $extra = static::extraOptions($element);
    if ($extra) {
      $element['#attributes']['data-neo-field-extra'] = Json::encode($extra);
    }

    // Ship the stored value's human label with the widget. Without it the
    // control would open showing a raw matcher key ("field_related_projects
    // ._entity:label") until the first search returned, which reads as data
    // corruption rather than as a saved setting.
    $value = (string) ($element['#value'] ?? '');
    if ($value !== '' && isset($extra[$value])) {
      $element['#attributes']['data-neo-field-label'] = $extra[$value];
      $element['#attributes']['data-neo-field-path'] = t('Special');
    }
    elseif ($value !== '') {
      $locator = \Drupal::service('neo_alchemist.field_match_locator');
      $shape = $locator->resolveShape((string) $element['#component'], (string) $element['#prop'], (string) $element['#shape']);
      $match = $shape ? static::lookup($locator, $shape, $element, $value) : NULL;
      // A key that no longer matches — the field was deleted, or the prop's
      // type changed under it — still renders, flagged, rather than silently
      // showing as blank and inviting someone to save the blank over it.
      $element['#attributes']['data-neo-field-label'] = $match['label'] ?? $value;
      $element['#attributes']['data-neo-field-path'] = $match['path'] ?? t('Not available on this entity type');
      if (!$match) {
        $element['#attributes']['data-neo-field-missing'] = 'true';
      }
    }

    $element['#attached']['library'][] = 'neo_alchemist/field-browser';
    return $element;
  }

  /**
   * Rejects a value that is not a match this shape actually offers.
   *
   * The control posts an arbitrary string, so the offer list has to be
   * re-derived and checked here — MatcherField::EXCLUDED_FIELD_TYPES is what
   * keeps a password hash from being a legal prop source, and a check that
   * only ran client-side would not enforce it.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   */
  public static function validateFieldSelect(&$element, FormStateInterface $form_state, &$complete_form): void {
    $value = (string) ($element['#value'] ?? '');
    if ($value === '') {
      $form_state->setValueForElement($element, '');
      return;
    }
    if (isset(static::extraOptions($element)[$value])) {
      $form_state->setValueForElement($element, $value);
      return;
    }
    $locator = \Drupal::service('neo_alchemist.field_match_locator');
    $shape = $locator->resolveShape((string) $element['#component'], (string) $element['#prop'], (string) $element['#shape']);
    if (!$shape || !static::lookup($locator, $shape, $element, $value)) {
      $form_state->setError($element, t('%value is not a field this property can take its value from.', [
        '%value' => $value,
      ]));
      return;
    }
    $form_state->setValueForElement($element, $value);
  }

  /**
   * Sets the rendered input value.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The element.
   */
  public static function preRenderFieldSelect(array $element): array {
    $element['#attributes']['value'] = (string) ($element['#value'] ?? '');
    // Element::setAttributes rather than hand-copying name/value: it also
    // carries #id onto the input, and ajax.js binds its behaviours by
    // document.getElementById(). Without the id the ajax settings are emitted
    // and then never bound to anything, so #ajax on this element silently does
    // nothing — no error, no request, just a dependent sub-form that never
    // appears.
    Element::setAttributes($element, ['id', 'name', 'value']);
    return $element;
  }

  /**
   * An endpoint for an element.
   *
   * @param array $element
   *   The element.
   * @param string $route
   *   The route name.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   */
  private static function route(array $element, string $route): Url {
    $query = array_filter([
      'all' => !empty($element['#all']) ? 1 : NULL,
      'entity_type' => $element['#entity_type'] ?? NULL,
      'bundle' => $element['#bundle'] ?? NULL,
    ]);
    return Url::fromRoute($route, [
      'component' => (string) $element['#component'],
      'prop' => (string) $element['#prop'],
      'shape' => (string) ($element['#shape'] ?: FieldMatchLocator::ROOT),
    ], ['query' => $query]);
  }

  /**
   * The synthetic, non-field choices for an element, as value => label.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The options.
   */
  private static function extraOptions(array $element): array {
    return array_map(
      static fn ($label) => (string) $label,
      array_filter((array) ($element['#extra'] ?? []))
    );
  }

  /**
   * Looks a stored key up against the element's own match list.
   *
   * @param \Drupal\neo_alchemist\FieldMatchLocator $locator
   *   The locator.
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The resolved shape.
   * @param array $element
   *   The element.
   * @param string $value
   *   The matcher key.
   *
   * @return array|null
   *   The match, or NULL.
   */
  private static function lookup(FieldMatchLocator $locator, ComponentShapePluginInterface $shape, array $element, string $value): ?array {
    return $locator->label(
      $shape,
      $value,
      !empty($element['#all']),
      $element['#entity_type'] ?? NULL,
      $element['#bundle'] ?? NULL,
    );
  }

}
