<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ViewsFilterTwig;

/**
 * Fills a views_filter prop from one of a bound view's exposed filters.
 *
 * The counterpart of the views_exposed_filters SLOT plugin for themes that
 * want to design the filter themselves. Where the slot hands Twig Drupal's
 * rendered form, this hands it data plus wiring helpers (a ViewsFilterTwig
 * object): label, current state, an options tree whose every entry carries a
 * ready-made URL, and form()/hidden()/checkbox()/radio()/textfield()/link()
 * methods — because an exposed filter is ultimately just a GET parameter, and
 * a designed link or hand-written GET form is as valid a submission surface
 * as the form Views renders.
 *
 * Covers options-style filters (selects, taxonomy — including hierarchy) and
 * text filters (fulltext search): a filter whose widget has no options
 * resolves with an empty options list plus its placeholder, for a designed
 * text input.
 *
 * Resolution timing, ordering constraint and the empty/preview semantics are
 * documented on the base class and modifyValue().
 *
 * @see \Drupal\neo_alchemist\ViewsFilterTwig
 * @see \Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsExposedFiltersSlot
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 */
#[ComponentValue(
  id: 'views_exposed_filter',
  label: new TranslatableMarkup('Views | Exposed Filter'),
  description: new TranslatableMarkup('Provide one exposed views filter as data for a designed filter UI.'),
  group: 'providers',
  ref_types: [
    'views_filter',
  ],
  // The base class takes and returns ViewExecutable in real signatures, so
  // this plugin cannot function — or even be safely offered — without views.
  provider: 'views',
)]
final class ViewsExposedFilterValue extends ViewsExposedFilterValueBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
      'filter' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);
    $form['#id'] = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form = $this->buildContextFormElement($form);

    $context = $form_state->getValue([...$form['#parents'], 'context'], $this->configuration['context']);
    if ($context && ($filters = $this->getExposedFilterOptions($context))) {
      $form['filter'] = [
        '#type' => 'select',
        '#title' => $this->t('Exposed filter'),
        '#description' => $this->t('The exposed filter to provide as data, by its query identifier.'),
        '#options' => $filters,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['filter'],
        '#required' => TRUE,
      ];
    }

    $form['reference'] = $this->buildReferenceElement();

    return $form;
  }

  /**
   * Builds the twig reference shown under the settings.
   *
   * The methods are the load-bearing half. An exposed filter is only a GET
   * parameter, so a designed UI is free to submit it however it likes — but
   * the attribute clusters that make that submission correct (the form's
   * method/action pair, the inputs carrying every OTHER filter's state, the
   * checked/aria-current flags) are not derivable from the data, and getting
   * them wrong fails silently. Documenting them next to the setting is the
   * difference between a filter UI that composes with its siblings and one
   * that clears them.
   *
   * @return array
   *   A details element.
   */
  protected function buildReferenceElement(): array {
    $name = $this->shape->getName();

    return $this->buildTwigReferenceElement(
      [
        'label' => $this->t('The filter&#039;s exposed label, for the trigger.'),
        'param' => $this->t('The query parameter this filter reads and writes.'),
        'multiple' => $this->t('TRUE when the filter accepts several values — checkboxes rather than links or radios.'),
        'active' => $this->t('TRUE when the visitor has applied this filter.'),
        'active_count' => $this->t('How many values are applied, for a "Markets (2)" trigger.'),
        'active_labels' => $this->t('The applied values&#039; labels. <code>@name.active_labels|first|default(@name.label)</code> is the usual single-select trigger.', ['@name' => $name]),
        'value' => $this->t('The applied values themselves, as strings.'),
        'options' => $this->t('The option tree. Each entry has <code>label</code>, <code>value</code>, <code>url</code> (that option applied), <code>active</code>, and <code>below</code> for the next level — taxonomy hierarchies come through nested, up to three deep.'),
        'reset_url' => $this->t('The current URL with this filter dropped — the "All" / "Clear" target.'),
        'placeholder' => $this->t('A text filter&#039;s placeholder, when the exposed filter defines one.'),
      ],
      [
        'getForm()' => $this->t('Attributes for the <code>&lt;form&gt;</code> tag: <code>method="get"</code> and the right action. Only needed when the UI submits a form; a filter rendered as links needs no form at all.'),
        'getHidden()' => $this->t('Every OTHER query argument as hidden inputs. <strong>Omitting this inside the form clears every other active filter on submit</strong> — the one mistake here that produces no error.'),
        'getCheckbox(option)' => $this->t('Name/value/checked for one option in a multi-select.'),
        'getRadio(option)' => $this->t('Name/value/checked for one option in a single-select form.'),
        'getTextfield(type)' => $this->t('Name/value/placeholder for a text filter&#039;s input. Pass <code>&#039;search&#039;</code> for search-box semantics.'),
        'getLink(option)' => $this->t('href plus <code>aria-current</code> for one option rendered as a link — the no-form path, since applying a filter is just a different URL.'),
      ],
      [
        '{# Single-select, as a dropdown of links — no form needed #}',
        '<a{{ ' . $name . '.getLink(option).addClass(\'…\') }}>{{ option.label }}</a>',
        '',
        '{# Multi-select, as a checkbox panel #}',
        '<form{{ ' . $name . '.getForm() }}>',
        '  {{ ' . $name . '.getHidden() }}{# keeps the sibling filters #}',
        '  <label><input{{ ' . $name . '.getCheckbox(option) }}> {{ option.label }}</label>',
        '  <button type="submit">{{ \'Apply\'|t }}</button>',
        '</form>',
      ],
      $this->unboundNote()
    );
  }

  /**
   * {@inheritdoc}
   *
   * Resolution happens here — the modify stage — and not in
   * provideDefaultValue(), deliberately. Defaults resolve at shape init,
   * inside loadPropShapes(), where two things go wrong: the views value
   * provider has not executed its view yet (it does so while its own prop's
   * render value builds, later), so the context is always empty there; and
   * anything that forces a shape build from init recurses fatally. The modify
   * stage runs during each prop's render-value build, in schema order — by
   * the time a views_filter prop (declared after the views-bound prop)
   * reaches it, the view is executed and the context registered.
   */
  public function modifyValue(mixed $value): mixed {
    $resolved = $this->resolveFilter();
    if ($resolved !== NULL) {
      return new ViewsFilterTwig($resolved);
    }
    // Unresolvable: no binding, no view, no such filter. The threaded $value
    // is the schema-example scaffolding (already wrapped by the shape) — keep
    // it for the editor preview, never for a visitor.
    return $this->shape->getComponent()->isPreview() ? $value : [];
  }

  /**
   * Resolves the configured exposed filter into the views_filter value.
   *
   * @return array|null
   *   The value, or NULL when the filter cannot be resolved.
   */
  protected function resolveFilter(): ?array {
    $context = $this->configuration['context'];
    $identifier = $this->configuration['filter'];
    if (!$context || !$identifier) {
      return NULL;
    }
    $view = $this->getContextView($context);
    if (!$view) {
      return NULL;
    }

    // A handler is required; options are not — a text filter (fulltext,
    // string) legitimately has none and resolves for a designed text input.
    $handler = $this->getExposedFilterHandler($view, $identifier);
    if (!$handler) {
      return NULL;
    }
    $flat = $this->getFlatOptions($view, $identifier, $handler);

    // The rendered value depends on the query string in both directions:
    // which option is active, and the URL every option links to.
    $this->addQueryCacheability();

    $multiple = !empty($handler->options['expose']['multiple']);
    $current = $this->getCurrentValues($view, $identifier);
    $query = $this->getRequestQuery();

    $activeLabels = [];
    foreach ($flat as $i => $item) {
      $active = in_array($item['value'], $current, TRUE);
      if ($active) {
        $activeLabels[] = $item['label'];
      }
      $optionQuery = $query;
      if ($multiple) {
        // Toggle membership.
        $values = $active
          ? array_values(array_diff($current, [$item['value']]))
          : array_merge($current, [$item['value']]);
        if ($values) {
          $optionQuery[$identifier] = $values;
        }
        else {
          unset($optionQuery[$identifier]);
        }
      }
      else {
        $optionQuery[$identifier] = $item['value'];
      }
      $flat[$i]['active'] = $active;
      $flat[$i]['url'] = $this->buildUrl($optionQuery);
    }

    // A text filter's "option label" is the entered text itself.
    if (!$flat && $current) {
      $activeLabels = $current;
    }

    $resetQuery = $query;
    unset($resetQuery[$identifier]);

    // Hidden inputs a hand-written GET form needs so submitting this filter
    // preserves every other query arg (search text, sibling filters, ...).
    $carry = [];
    foreach ($resetQuery as $name => $carryValue) {
      if (is_array($carryValue)) {
        foreach ($carryValue as $one) {
          if (is_scalar($one)) {
            $carry[] = ['name' => $name . '[]', 'value' => (string) $one];
          }
        }
      }
      elseif (is_scalar($carryValue)) {
        $carry[] = ['name' => $name, 'value' => (string) $carryValue];
      }
    }

    return [
      'label' => (string) ($handler->options['expose']['label'] ?? $identifier),
      'param' => $identifier,
      'multiple' => $multiple,
      'active' => (bool) $current,
      'active_count' => count($current),
      'active_labels' => $activeLabels,
      'value' => $current,
      'placeholder' => (string) ($view->exposed_widgets[$identifier]['#placeholder'] ?? ''),
      'reset_url' => $this->buildUrl($resetQuery),
      'action' => $this->getRequestBasePath(),
      'carry' => $carry,
      'options' => $this->nestOptions($flat),
    ];
  }

  /**
   * Nests a depth-annotated flat list into the below[] tree.
   *
   * @param array $flat
   *   Items of {label, value, depth, url, active}, tree order.
   *
   * @return array
   *   Nested options; each item carries label, value, url, active, below.
   */
  protected function nestOptions(array $flat): array {
    $byParent = [];
    $lastAtDepth = [];
    foreach ($flat as $i => $item) {
      $depth = $item['depth'];
      // A depth gap (parent filtered out of the options) attaches to the
      // nearest surviving ancestor rather than being dropped.
      while ($depth > 0 && !isset($lastAtDepth[$depth - 1])) {
        $depth--;
      }
      $parent = $depth > 0 ? $lastAtDepth[$depth - 1] : -1;
      $byParent[$parent][] = $i;
      $lastAtDepth[$depth] = $i;
      foreach (array_keys($lastAtDepth) as $d) {
        if ($d > $depth) {
          unset($lastAtDepth[$d]);
        }
      }
    }
    $build = function (int $parent) use (&$build, $byParent, $flat): array {
      $out = [];
      foreach ($byParent[$parent] ?? [] as $i) {
        $out[] = [
          'label' => $flat[$i]['label'],
          'value' => $flat[$i]['value'],
          'url' => $flat[$i]['url'],
          'active' => $flat[$i]['active'],
          'below' => $build($i),
        ];
      }
      return $out;
    };
    return $build(-1);
  }

}
