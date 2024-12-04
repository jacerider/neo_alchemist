<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernal subscriber for Neo | Alchemist module.
 */
final class KernelSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a KernelSubscriber object.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteBuilderInterface $routeBuilder,
  ) {}

  /**
   * Kernel request event handler.
   */
  public function onKernelTerminate(TerminateEvent $event): void {
    if ($this->state->get('neo_alchemist.rebuild_needed') === TRUE) {
      ksm('PURGE');
      $this->routeBuilder->setRebuildNeeded();
      $this->entityTypeManager->clearCachedDefinitions();
      $this->state->delete('neo_alchemist.rebuild_needed');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => ['onKernelTerminate'],
    ];
  }

}
