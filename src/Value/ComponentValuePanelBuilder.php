<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeStylePluginInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Builds the value panel both component value editors present.
 *
 * The styles accordion, the values container, the per-prop shape forms and the
 * style-plugin branch that lifts style props into the accordion were built
 * twice — once in InstanceComponentForm and once in SdcPreviewForm — as were
 * the hidden refresh button and the two DOM ids the editor's TypeScript matches
 * on. Building a form and harvesting its values are different jobs, so this is
 * deliberately not part of ComponentPropValueHarvester.
 *
 * The three flags on ::build() are the whole of what the two editors disagree
 * about; each is documented where it is declared, because none of them is a
 * preference — they are consequences of one editor configuring a saved
 * component and the other previewing an unsaved one.
 *
 * @see \Drupal\neo_alchemist\ComponentPropValueHarvester
 */
final class ComponentValuePanelBuilder {

  use IconTrait;
  use StringTranslationTrait;

  /**
   * The DOM id both value editors give their form element.
   *
   * Half of a client-server contract: src/js/component-ajax-form.ts binds its
   * debounced input watcher, its CKEditor hook and its build-id tracking to
   * this id, and re-reads the form element by it on every AJAX rebuild. The
   * instance editor also stamps it as a class hook for its own CSS.
   *
   * Declared here rather than applied here — each editor sets `#id` on its own
   * form — because this class is where the value panel's contract with the
   * client lives. The client reads it from the settings ::attachClient()
   * publishes, so this is the only copy of the name anywhere.
   */
  public const FORM_ID = 'neo-alchemist--instance-component-form';

  /**
   * The DOM id both value editors give their hidden refresh button.
   *
   * The other half of the same contract: component-ajax-form.ts names this id
   * as the AJAX selector it submits the form through on every debounced input.
   *
   * @see \Drupal\neo_alchemist\Value\ComponentValuePanelBuilder::FORM_ID
   */
  public const REFRESH_ID = 'neo-alchemist--refresh';

  /**
   * Attaches the editor client and the DOM ids it matches on.
   *
   * The libraries and the settings go on together deliberately. The behavior
   * has no literals left to fall back on, so settings without the library is
   * dead weight and the library without the settings is a dead behavior —
   * publishing both from one place is what stops either happening.
   *
   * Its corollary is worth stating, because the client depends on it: the ids
   * are present on exactly the pages that have a value editor, so the
   * behavior's early return on missing settings is "no editor here", not an
   * error path.
   *
   * @param array $form
   *   The form to attach to, modified by reference.
   */
  public function attachClient(array &$form): void {
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax';
    $form['#attached']['library'][] = 'neo_alchemist/component.ajax.form';
    $form['#attached']['drupalSettings']['neoAlchemist']['valueEditor'] = [
      'formId' => self::FORM_ID,
      'refreshId' => self::REFRESH_ID,
    ];
  }

  /**
   * Builds the styles accordion, the values container and the prop forms.
   *
   * The two elements are returned rather than written into $form because both
   * editors place other elements between them — the instance editor puts its
   * Context accordion there — and top-level render order is array order.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component whose prop shapes are being edited.
   * @param array $form
   *   The complete form the prop subforms hang off. Passed by reference
   *   because SubformState::createForSubform() takes its parent form that way;
   *   nothing here writes to it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $sortStylesByTitle
   *   Whether style props are collected and appended in title order rather
   *   than left in schema order. The instance editor sorts because a placed
   *   component's styles are browsed as a list; the preview workspace keeps
   *   schema order so a developer sees the component's own declaration.
   * @param bool $describeStyles
   *   Whether the style's own card carries the shape description as visible
   *   text. Both editors decline: the widget inside already carries the same
   *   sentence, and neo_tooltip turns any `#input` element's description into
   *   a hover tooltip, so a card-level copy prints it twice. The accordion
   *   holder used to swallow it, which is why this only became visible once
   *   the non-collapsing variant started rendering a real fieldset.
   * @param bool $hideOptionControls
   *   Whether to hide the per-prop `_options` controls (Allow Edit / Default /
   *   Hide). They configure a saved component, which is what the instance
   *   editor is doing and what the preview workspace is not.
   * @param bool $collapsibleStyles
   *   Whether the styles section is a collapsing accordion. The preview
   *   workspace keeps it, because there the styles sit in one long form
   *   alongside everything else. The instance editor turns it off: its styles
   *   have a tab of their own, so collapsing them only hides the values the
   *   editor exists to show.
   *
   * @return array
   *   The `styles` and `values` elements, keyed by those names.
   */
  public function build(ComponentInterface $component, array &$form, FormStateInterface $form_state, bool $sortStylesByTitle = TRUE, bool $describeStyles = TRUE, bool $hideOptionControls = FALSE, bool $collapsibleStyles = TRUE): array {
    $panel = [
      'styles' => $collapsibleStyles ? [
        '#type' => 'accordion',
        '#title' => $this->icon('Styles', 'palette'),
        '#access' => FALSE,
        '#neo_size' => 'xs',
      ] : [
        // A plain container still holds the group: #group relocation is core
        // (processGroup / preRenderGroup), and the accordion only adds Alpine,
        // the accordion_item wrapper and #neo_size propagation on top.
        // NeoProcess::details() reads $group['#type'] unguarded, so the holder
        // must keep a #type. The caller sizes this holder for everything that
        // stays inside it; the relocated style cards size themselves below.
        '#type' => 'container',
        '#access' => FALSE,
      ],
      'values' => [
        '#title' => $this->t('Values'),
        '#type' => 'container',
        '#access' => FALSE,
      ],
    ];

    $styleElements = [];
    foreach ($component->getPropShapes() as $propName => $shape) {
      if (!$shape->access('update')) {
        continue;
      }
      $panel['values']['#access'] = TRUE;
      $subform = [
        '#type' => 'container',
        '#parents' => ['values'],
      ];
      $subform_state = SubformState::createForSubform($subform, $form, $form_state);
      $elementForm = $shape->getForm($subform, $subform_state);
      if ($hideOptionControls) {
        $this->hideOptionControls($elementForm);
      }
      if ($shape instanceof ComponentShapeStylePluginInterface) {
        $panel['styles']['#access'] = TRUE;
        // A fieldset when the section does not collapse: it renders the title
        // as a legend with no disclosure affordance, which is what an
        // always-open card wants. Both types carry processGroup, so the
        // relocation into the styles holder is unaffected either way.
        $elementForm['#type'] = $collapsibleStyles ? 'details' : 'fieldset';
        $elementForm['#title'] = $shape->getTitle();
        if (!$collapsibleStyles) {
          // A style card has to carry its own size, because `#group` puts it
          // beyond the reach of the cascade: preRenderGroup() relocates it
          // *after* NeoBasePreRender::neoSize() has run on the holder it leaves
          // and the holder it joins, so it inherits from neither. The accordion
          // variant does not need this — Accordion::preRender() walks its
          // members and assigns `#neo_size` itself. A plain container has no
          // such step, which is the whole difference between the two paths.
          $elementForm['#neo_size'] = 'xs';
        }
        if ($describeStyles) {
          $elementForm['#description'] = $shape->getDescription();
        }
        $elementForm['#group'] = 'styles';
        $elementForm['widget']['widget']['#title'] = '';
        if ($sortStylesByTitle) {
          // Held back so the whole set can be ordered together, then appended
          // after the non-style props.
          $styleElements[$propName] = $elementForm;
          continue;
        }
      }
      $panel['values'][$propName] = $elementForm;
    }

    if ($sortStylesByTitle) {
      uasort($styleElements, static function ($a, $b) {
        return strcmp((string) $a['#title'], (string) $b['#title']);
      });
      $panel['values'] += $styleElements;
    }

    $this->groupPropForms($component, $panel['values']);

    return $panel;
  }

  /**
   * Renders props declaring `form_group` inside the named prop's fieldset.
   *
   * Some props belong to another prop in the author's head but cannot be a
   * child of it in the schema — a shared object prop-def such as `heading` is
   * fixed, so a component wanting an optional link on its heading title has to
   * declare that link as a sibling. This puts the two back together on the
   * form without touching the schema.
   *
   * Grouping is Drupal's `#group`, NOT a move of the built form. The element
   * stays exactly where it is in `$values`, because
   * ComponentPropValueHarvester::harvest() looks each prop up as
   * `$form['values'][$propName]` and skips what it cannot find there — nesting
   * the array would drop the prop's value on save with no error. `#group`
   * relocates at pre-render only, so `#parents`, `#tree` and the harvest are
   * all untouched.
   *
   * Runs after the build loop rather than inside it so a prop may name a host
   * declared after it; in-loop, the host's element would not exist yet.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component whose prop shapes are being edited.
   * @param array $values
   *   The values container, by reference.
   *
   * @see \Drupal\Core\Render\Element\RenderElementBase::preRenderGroup()
   */
  private function groupPropForms(ComponentInterface $component, array &$values): void {
    foreach ($component->getPropShapes() as $propName => $shape) {
      $host = $shape->getFormGroup();
      // A style prop is already grouped into the styles accordion, and core
      // keeps only the last #group, so honouring both would silently drop it
      // out of the accordion.
      if (!$host || $shape instanceof ComponentShapeStylePluginInterface) {
        continue;
      }
      // An absent host is not fatal: core renders an orphaned group member at
      // its original location. `neo:alchemist:validate` reports the typo.
      if ($host === $propName || !isset($values[$propName], $values[$host]['#parents'])) {
        continue;
      }

      // Give the host's children explicit, evenly spaced weights in their
      // current order. Without this, placement rides on the decimal
      // placeholders FormBuilder assigns from array order, which shift as soon
      // as the host gains a child — so "after the title" would quietly become
      // "after the subtitle". `_options` is left alone: it carries its own
      // weight to sit in the fieldset legend.
      $weights = [];
      $weight = 0;
      foreach (Element::children($values[$host]) as $child) {
        if ($child === '_options') {
          continue;
        }
        $weights[$child] = $weight += 10;
        $values[$host][$child]['#weight'] = $weights[$child];
      }

      $after = $shape->getFormGroupAfter();
      // Half a step lands it between the named child and the next one. An
      // unknown name falls through to the end, which is also where a prop
      // naming no sibling goes.
      $values[$propName]['#weight'] = isset($weights[$after])
        ? $weights[$after] + 5
        : $weight + 10;
      $values[$propName]['#group'] = implode('][', $values[$host]['#parents']);
    }
  }

  /**
   * Builds the hidden refresh button that drives the live preview.
   *
   * The client submits the form through this button on every debounced input
   * change, so both editors need one and it has to carry ::REFRESH_ID. The
   * `::submitRefresh` and `::ajaxRefresh` handlers are named relative to the
   * form object, which each editor supplies itself.
   *
   * @return array
   *   The refresh submit element.
   */
  public function buildRefresh(): array {
    return [
      '#type' => 'submit',
      '#id' => self::REFRESH_ID,
      '#op' => 'refresh',
      '#value' => $this->t('Refresh'),
      '#submit' => ['::submitRefresh'],
      '#ajax' => [
        'callback' => '::ajaxRefresh',
      ],
      '#weight' => -1000,
      '#prefix' => '<div class="hidden">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Recursively hides the per-prop option controls in a shape form.
   *
   * Shape forms add an `_options` group (Allow Edit / Default / Hide) for each
   * prop and nested prop. Setting `#access` to FALSE removes them from the UI
   * while keeping their default values available to form processing, so
   * harvesting the submitted values is unaffected.
   *
   * @param array $element
   *   The render element to process, modified by reference.
   */
  private function hideOptionControls(array &$element): void {
    foreach (Element::children($element) as $key) {
      if ($key === '_options') {
        $element[$key]['#access'] = FALSE;
        continue;
      }
      if (is_array($element[$key])) {
        $this->hideOptionControls($element[$key]);
      }
    }
  }

}
