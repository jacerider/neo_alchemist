<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsSlot;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that paged views in views slots each get their own pager element.
 *
 * Every view pages on element 0 unless its config says otherwise, so two
 * paged views slotted onto one page shared a `page` parameter: paging one
 * paged the other, and emptied it when it had fewer pages.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsSlot::claimPagerElement()
 */
#[Group('neo_alchemist')]
class ViewsSlotPagerElementTest extends ViewsKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'user', 'neo_settings', 'neo_alchemist'];

  /**
   * {@inheritdoc}
   */
  public static $testViews = ['test_pager_full', 'test_mini_pager', 'test_mini_pager_ajax', 'test_pager_none'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    parent::setUp($import_test_views);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * Renders a view the way a views slot does.
   *
   * @param string $viewId
   *   The view.
   *
   * @return \Drupal\views\ViewExecutable
   *   The view the slot rendered.
   */
  private function renderSlot(string $viewId): ViewExecutable {
    $element = ViewsSlot::preRenderViewElement([
      '#view_id' => $viewId,
      '#view_display_id' => 'default',
      '#view_items_per_page' => NULL,
      '#view_items_offset' => NULL,
      '#view_arguments' => [],
      '#slot_reference' => 'component:' . $viewId,
    ]);
    return $element['#view'];
  }

  /**
   * Tests that the first paged view keeps its element and later ones move.
   */
  public function testLaterViewsMoveOffTakenElements(): void {
    $this->assertSame(0, $this->renderSlot('test_pager_full')->pager->getPagerId());
    $this->assertSame(1, $this->renderSlot('test_mini_pager')->pager->getPagerId());
    // The same view placed twice is two pagers too.
    $this->assertSame(2, $this->renderSlot('test_pager_full')->pager->getPagerId());
  }

  /**
   * Tests that an element claimed outside views slots is also avoided.
   */
  public function testElementTakenElsewhereIsAvoided(): void {
    $this->container->get('pager.manager')->createPager(20, 5, 0);
    $this->assertSame(1, $this->renderSlot('test_pager_full')->pager->getPagerId());
  }

  /**
   * Tests that a view that does not page claims nothing.
   */
  public function testUnpagedViewClaimsNothing(): void {
    $this->renderSlot('test_pager_none');
    $this->assertSame(0, $this->renderSlot('test_pager_full')->pager->getPagerId());
  }

  /**
   * Tests that the slot reference reaches the view's AJAX settings.
   */
  public function testSlotReferenceReachesAjaxSettings(): void {
    $view = $this->renderSlot('test_mini_pager_ajax');
    $settings = $view->element['#attached']['drupalSettings']['views']['ajaxViews']['views_dom_id:' . $view->dom_id];
    $this->assertSame('component:test_mini_pager_ajax', $settings[ViewsSlot::AJAX_PARAMETER]);
    $this->assertSame(0, $settings['pager_element']);
  }

}
