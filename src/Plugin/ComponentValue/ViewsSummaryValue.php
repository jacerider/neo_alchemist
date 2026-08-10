<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\views\ViewExecutable;

/**
 * Fills a views_summary prop with a bound view's result counts.
 *
 * The number a listing shows next to its filters is almost never the number
 * of items it rendered — that is one page. This reads the counts off the same
 * executed view the `views` provider binds: the total, the page window
 * (start/end), and the paging shape. The field set mirrors core's views
 * `result` area handler tokens as data, so the template decides the wording.
 *
 * Resolution timing and the ordering constraint are documented on the base
 * class; the empty/preview semantics on modifyValue() below.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsContextValueBase
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 */
#[ComponentValue(
  id: 'views_summary',
  label: new TranslatableMarkup('Views | Result Summary'),
  description: new TranslatableMarkup("Provide a bound view's result counts — total, page window, page count — as data."),
  group: 'providers',
  ref_types: [
    'views_summary',
  ],
  provider: 'views',
)]
final class ViewsSummaryValue extends ViewsContextValueBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);
    // Must be set before buildContextFormElement(): it is the AJAX rewrap
    // target, and it is per plugin instance.
    $form['#id'] = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());

    $form['info'] = [
      '#markup' => '<p>' . $this->t('Reads the result counts off the view bound below. They reach twig as plain numbers — this provider renders no text of its own, so the wording, pluralization and translation stay the template’s decision.') . '</p>',
      '#weight' => -10,
    ];
    $form = $this->buildContextFormElement($form);
    $form['reference'] = $this->buildReferenceElement();

    return $form;
  }

  /**
   * Builds the twig reference shown under the context select.
   *
   * @return array
   *   A details element.
   */
  protected function buildReferenceElement(): array {
    $name = $this->shape->getName();

    return $this->buildTwigReferenceElement(
      [
        'total' => $this->t('Results matching the filters, across every page. This is the number a "N results" label wants.'),
        'count' => $this->t('Results on the page being rendered.'),
        'start' => $this->t('Number of the first result on this page, counting from 1. Zero when nothing matched.'),
        'end' => $this->t('Number of the last result on this page. Zero when nothing matched.'),
        'page' => $this->t('Current page, counting from 1.'),
        'pages' => $this->t('Total number of pages. Use <code>@name.pages > 1</code> to test whether paging is in play at all.', ['@name' => $name]),
        'per_page' => $this->t('Results per page, or 0 when the view shows everything.'),
        'exact' => $this->t('FALSE when the view ran no count query — with a mini/some/none pager, or a Search API search that skipped the count, <code>total</code> is only a lower bound. TRUE with a full pager, and whenever paging is off.'),
      ],
      [],
      [
        '{# A count, pluralized #}',
        '{% trans %}{{ ' . $name . '.total }} result{% plural ' . $name . '.total %}{{ ' . $name . '.total }} results{% endtrans %}',
        '',
        '{# A range #}',
        '{{ \'Showing @start–@end of @total\'|t({',
        '  \'@start\': ' . $name . '.start,',
        '  \'@end\': ' . $name . '.end,',
        '  \'@total\': ' . $name . '.total,',
        '}) }}',
        '',
        '{# Flag an inexact total #}',
        '{{ ' . $name . '.total }}{{ ' . $name . '.exact ? \'\' : \'+\' }}',
      ],
      $this->t('With no view bound — an unconfigured instance, or the editor preview — this prop resolves to nothing and <code>@name</code> is undefined rather than empty, so give the template a fallback: <code>{{ @name.total ?? items|length }}</code>.', [
        '@name' => $name,
      ])
    );
  }

  /**
   * {@inheritdoc}
   *
   * Resolution happens here — the modify stage — and not in
   * provideDefaultValue(). Defaults resolve at shape init, inside
   * loadPropShapes(), where the views value provider has not executed its
   * view yet (it does so while its own prop's render value builds, later).
   * The modify stage runs during each prop's render-value build, in schema
   * order — so by the time a views_summary prop declared after the
   * views-bound prop reaches it, the view is executed and registered.
   */
  public function modifyValue(mixed $value): mixed {
    $context = $this->configuration['context'];
    $view = $context ? $this->getContextView($context) : NULL;
    if ($view) {
      // The window depends on ?page= and the total on the exposed filters, so
      // this value varies by the query string in both directions.
      $this->addQueryCacheability();
      return $this->summarize($view);
    }
    // Unresolvable: no binding, no view. The threaded $value is the
    // schema-example scaffolding — keep it for the editor preview, never for
    // a visitor. An empty array is dropped from #props entirely
    // (Component::isPropValueEmpty()), so the prop is simply undefined in
    // twig and a template's own fallback applies.
    return $this->shape->getComponent()->isPreview() ? $value : [];
  }

  /**
   * Reads the result counts off an executed view.
   *
   * The subtle part is `total`. Core's Sql::execute() assigns
   * `$view->total_rows = $view->pager->getTotalItems()` UNCONDITIONALLY,
   * outside the guard that decides whether to run the count query — so on a
   * SQL view the property is never NULL, it is a plausible-looking wrong
   * number. With a `none`/`some` pager it is 0; with `mini` it is
   * `page * perPage + count($result)`, a lower bound that reads like a total.
   * Only Search API leaves it NULL (SearchApiQuery skips the assignment when
   * it skipped the count). So the question to ask is "did a count query
   * run?", never "is total_rows set?" — a NULL check alone would report
   * "0 results" on an unlimited view displaying five rows.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   *
   * @return array
   *   The views_summary value.
   */
  protected function summarize(ViewExecutable $view): array {
    $rows = is_array($view->result) ? count($view->result) : 0;
    $perPage = (int) $view->getItemsPerPage();
    $index = (int) $view->getCurrentPage();

    $counted = !empty($view->get_total_rows)
      || (isset($view->pager) && $view->pager->useCountQuery());

    $total = is_numeric($view->total_rows) ? (int) $view->total_rows : NULL;
    if (!$counted || $total === NULL) {
      // The same lower bound Mini computes — and the EXACT total whenever
      // paging is off, because then every matching row is present.
      $total = $index * $perPage + $rows;
    }

    return [
      'total' => $total,
      'count' => $rows,
      // Derived from the rows actually present, never from $total: a lower
      // bound must not produce a window that contradicts the page.
      'start' => $rows ? ($index * $perPage) + 1 : 0,
      'end' => $rows ? ($index * $perPage) + $rows : 0,
      // 1-based, matching core's @current_page.
      'page' => $index + 1,
      'pages' => $total ? ($perPage > 0 ? (int) ceil($total / $perPage) : 1) : 0,
      'per_page' => $perPage,
      // Unlimited display means the tally IS the total, count query or not.
      'exact' => $counted || $perPage <= 0,
    ];
  }

}
