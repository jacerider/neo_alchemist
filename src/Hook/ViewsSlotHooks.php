<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\EventSubscriber\ViewsAjaxSlotSubscriber;
use Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsSlot;
use Drupal\views\ViewExecutable;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps a views slot's overrides when its view pages over AJAX.
 *
 * ViewsSlot overrides items per page, offset and pager element at render
 * time. Core's views AJAX controller rebuilds the view from its config alone,
 * so without this the first AJAX page change would show the view's own page
 * size, on pager element 0, colliding with whatever else pages on the page.
 *
 * The view's AJAX settings carry a reference to its slot; the request comes
 * back with it, ViewsAjaxSlotSubscriber moves it off the query, and the saved
 * slot is loaded here to reapply the overrides. Nothing but the reference
 * comes from the browser, so the overrides cannot be tampered with.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsSlot
 * @see \Drupal\neo_alchemist\EventSubscriber\ViewsAjaxSlotSubscriber
 */
class ViewsSlotHooks {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_views_pre_view().
   */
  #[Hook('views_pre_view')]
  public function viewsPreView(ViewExecutable $view, string $display_id, array &$args): void {
    $reference = $this->requestStack->getCurrentRequest()?->attributes->get(ViewsAjaxSlotSubscriber::ATTRIBUTE);
    if (!is_string($reference) || !str_contains($reference, ':')) {
      return;
    }
    [$componentId, $uuid] = explode(':', $reference, 2);
    $component = $this->entityTypeManager->getStorage('neo_component')->load($componentId);
    if (!$component instanceof ComponentInterface) {
      return;
    }
    foreach ($component->getSlots() as $slot) {
      $plugin = $slot->getPlugin($uuid);
      if ($plugin instanceof ViewsSlot) {
        // The views AJAX controller stores the requested element here.
        $element = $view->display_handler->getOption('pager_element');
        $plugin->applyToAjaxView($view, is_numeric($element) ? (int) $element : NULL);
        return;
      }
    }
  }

  /**
   * Implements hook_views_pre_render().
   *
   * Runs after views' own implementation, which replaces the AJAX settings.
   */
  #[Hook('views_pre_render', order: Order::Last)]
  public function viewsPreRender(ViewExecutable $view): void {
    $reference = $view->element['#' . ViewsSlot::AJAX_PARAMETER] ?? NULL;
    $key = 'views_dom_id:' . $view->dom_id;
    if ($reference && isset($view->element['#attached']['drupalSettings']['views']['ajaxViews'][$key])) {
      $view->element['#attached']['drupalSettings']['views']['ajaxViews'][$key][ViewsSlot::AJAX_PARAMETER] = $reference;
    }
  }

}
