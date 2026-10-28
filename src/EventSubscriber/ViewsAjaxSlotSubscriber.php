<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\neo_alchemist\Plugin\ComponentSlot\ViewsSlot;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Moves a views slot reference off a views AJAX request's query.
 *
 * Views AJAX sends every key of a view's AJAX settings as a query parameter,
 * and the controller builds pager links and the browser URL from whatever
 * query it is left with. It strips its own keys but not ours, so the slot
 * reference is moved to a request attribute before the controller runs.
 *
 * @see \Drupal\neo_alchemist\Hook\ViewsSlotHooks
 */
final class ViewsAjaxSlotSubscriber implements EventSubscriberInterface {

  /**
   * The request attribute holding the slot reference.
   */
  public const ATTRIBUTE = '_neo_alchemist_views_slot';

  /**
   * Kernel request event handler.
   */
  public function onKernelRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'views.ajax') {
      return;
    }
    $reference = $request->query->get(ViewsSlot::AJAX_PARAMETER, $request->request->get(ViewsSlot::AJAX_PARAMETER));
    $request->query->remove(ViewsSlot::AJAX_PARAMETER);
    $request->request->remove(ViewsSlot::AJAX_PARAMETER);
    if (is_string($reference)) {
      $request->attributes->set(self::ATTRIBUTE, $reference);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the router (32) has matched the route.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

}
