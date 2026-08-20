<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Value;

use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\neo_alchemist\Plugin\ComponentValue\ViewsSummaryValue;
use Drupal\Tests\UnitTestCase;
use Drupal\views\Plugin\views\pager\PagerPluginBase;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how the `views_summary` provider reads counts off an executed view.
 *
 * The entire risk of this provider is one decision: whether $view->total_rows
 * can be believed. Core's Sql::execute() assigns it UNCONDITIONALLY, outside
 * the guard that decides whether to run the count query — so it is never NULL
 * on a SQL view, it is a plausible-looking wrong number. That makes the
 * obvious implementation (`$view->total_rows ?? fallback`) wrong in two
 * directions at once, and wrong silently: it reports "0 results" on an
 * unlimited view showing five rows, and a lower bound as a total on a mini
 * pager. Hence the provider asks "did a count query run?" instead.
 *
 * The mutation this pins: replacing the useCountQuery()/get_total_rows test
 * with an is-total_rows-set test. That mutation passes every `full` pager case
 * (rows 1-3 below) — which is the only pager this site's views currently use —
 * so the none/some/mini/search-api rows are what make this test non-vacuous.
 *
 * Arithmetic only; the plumbing that gets a view here (context registration,
 * modify-stage ordering) is the base class's and is exercised on a real page.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsSummaryValue
 */
#[Group('neo_alchemist')]
class ViewsSummaryValueTest extends UnitTestCase {

  use ShapeDoubleTrait;

  /**
   * Builds an executed-view double.
   *
   * $result, $total_rows, $pager and $get_total_rows are all public untyped
   * properties on ViewExecutable, so assigning them directly reproduces the
   * post-execute state exactly.
   *
   * @param int $rows
   *   Rows on this page.
   * @param int|null $totalRows
   *   The value core left in $view->total_rows.
   * @param bool $useCountQuery
   *   What the display's pager reports.
   * @param int $perPage
   *   Items per page; 0 means unlimited.
   * @param int $index
   *   The 0-based current page.
   */
  private function view(int $rows, ?int $totalRows, bool $useCountQuery, int $perPage, int $index): ViewExecutable {
    $pager = $this->createMock(PagerPluginBase::class);
    $pager->method('useCountQuery')->willReturn($useCountQuery);

    $view = $this->createMock(ViewExecutable::class);
    $view->method('getItemsPerPage')->willReturn($perPage);
    $view->method('getCurrentPage')->willReturn($index);
    $view->result = array_fill(0, $rows, NULL);
    $view->total_rows = $totalRows;
    $view->pager = $pager;

    return $view;
  }

  /**
   * Runs the provider's summarize() against a view double.
   */
  private function summarize(ViewExecutable $view): array {
    $plugin = new ViewsSummaryValue(
      'views_summary',
      [],
      $this->unusedShape(),
      ['context' => 'items'],
    );
    $method = new \ReflectionMethod($plugin, 'summarize');
    return $method->invoke($plugin, $view);
  }

  /**
   * The total/exact truth table.
   *
   * @return array
   *   Cases of [rows, total_rows, useCountQuery, per page, page index,
   *   expected total, expected exact].
   */
  public static function totalCases(): array {
    return [
      // A `full` pager runs a count query, so total_rows is the real total.
      'full, page 1 of 142' => [30, 142, TRUE, 30, 0, 142, TRUE],
      'full, page 2 of 142' => [30, 142, TRUE, 30, 1, 142, TRUE],
      'full, filtered to 4' => [4, 4, TRUE, 30, 0, 4, TRUE],
      // `none` runs no count query and leaves total_rows at 0 — the case a
      // NULL check misses entirely. Paging is off, so the tally IS exact.
      'none, unlimited, 5 rows' => [5, 0, FALSE, 0, 0, 5, TRUE],
      // `some` caps the result set; the cap is not a total.
      'some, limit 5' => [5, 0, FALSE, 5, 0, 5, FALSE],
      // `mini` leaves a lower bound that reads exactly like a real total.
      'mini, page 2 at 30' => [30, 60, FALSE, 30, 1, 60, FALSE],
      // Search API is the only path that really leaves NULL.
      'search api, skipped count' => [30, NULL, FALSE, 30, 1, 60, FALSE],
      // Nothing matched.
      'full, no results' => [0, 0, TRUE, 30, 0, 0, TRUE],
    ];
  }

  /**
   * Tests that total and exact follow the count query, not total_rows.
   */
  #[DataProvider('totalCases')]
  public function testTotalAndExactness(int $rows, ?int $totalRows, bool $useCountQuery, int $perPage, int $index, int $expectedTotal, bool $expectedExact): void {
    $summary = $this->summarize($this->view($rows, $totalRows, $useCountQuery, $perPage, $index));

    $this->assertSame($expectedTotal, $summary['total']);
    $this->assertSame($expectedExact, $summary['exact']);
    // The premise: every case really did put the stated value in total_rows,
    // so a case can never pass by the double failing to reproduce the state.
    $this->assertSame($rows, $summary['count']);
  }

  /**
   * An explicit get_total_rows overrides the pager's answer.
   *
   * Another area handler on the same view (core's `result` area does this)
   * forces the count query, so total_rows becomes real even though the pager
   * would not have asked for it.
   */
  public function testGetTotalRowsForcesExactness(): void {
    $view = $this->view(30, 142, FALSE, 30, 1);
    $view->get_total_rows = TRUE;

    $summary = $this->summarize($view);

    $this->assertSame(142, $summary['total']);
    $this->assertTrue($summary['exact']);
  }

  /**
   * The page window is derived from rows present, never from the total.
   *
   * A lower-bound total must not be able to produce a window that contradicts
   * the page the visitor is looking at.
   */
  public function testPageWindow(): void {
    // Page 3 (0-based 2) of 30, holding a short final page of 12.
    $summary = $this->summarize($this->view(12, 72, TRUE, 30, 2));

    $this->assertSame(61, $summary['start']);
    $this->assertSame(72, $summary['end']);
    $this->assertSame(3, $summary['page'], 'page is 1-based, matching core @current_page.');
    $this->assertSame(3, $summary['pages']);
    $this->assertSame(30, $summary['per_page']);
  }

  /**
   * An empty result set collapses the window rather than inventing one.
   */
  public function testEmptyResultSet(): void {
    $summary = $this->summarize($this->view(0, 0, TRUE, 30, 0));

    $this->assertSame(0, $summary['start']);
    $this->assertSame(0, $summary['end']);
    $this->assertSame(0, $summary['pages']);
    // Still 1: there is no page zero, and core's @current_page is likewise
    // never below 1.
    $this->assertSame(1, $summary['page']);
  }

  /**
   * With paging off, everything is one page.
   */
  public function testUnlimitedIsOnePage(): void {
    $summary = $this->summarize($this->view(5, 0, FALSE, 0, 0));

    $this->assertSame(1, $summary['start']);
    $this->assertSame(5, $summary['end']);
    $this->assertSame(1, $summary['pages'], 'A division by per_page of 0 must not happen.');
    $this->assertSame(0, $summary['per_page']);
  }

  /**
   * Every field is a real int/bool, because modifyValue()'s return is final.
   *
   * BuildRenderValue() runs resolveValue() then preRenderValue() then the
   * modify chain — nothing casts afterwards, so what this returns is what
   * twig prints.
   */
  public function testFieldTypes(): void {
    $summary = $this->summarize($this->view(30, 142, TRUE, 30, 0));

    foreach (['total', 'count', 'start', 'end', 'page', 'pages', 'per_page'] as $field) {
      $this->assertIsInt($summary[$field], "$field must be an int.");
    }
    $this->assertIsBool($summary['exact']);
  }

}
