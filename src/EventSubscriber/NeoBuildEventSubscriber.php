<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Act on build events.
 *
 * @package Drupal\custom_events\EventSubscriber
 */
class NeoBuildEventSubscriber implements EventSubscriberInterface {

  /**
   * Subscribe to the Neo build event dispatched.
   *
   * @param \Drupal\neo_build\Event\NeoBuildEvent $event
   *   The neo build event.
   */
  public function onBuild(NeoBuildEvent $event) {
    $collection = $event->getCollection();
    // Only front-end extensions are considered.
    $extensions = $event->getExtensions();
    foreach ($extensions as $id => $extension) {
      $path = $extension->getPath();
      $defPath = $path . '/' . $id . '.neo_component_prop_defs.yml';
      if (file_exists($defPath)) {
        $collection->addTailwindSource($id . ':Props', $defPath);
      }
      if (is_dir($path . '/components')) {
        $collection->addTailwindSource($id . ':Components', $path . '/components/**/*.{yml,twig}');
      }
    }
    $collection->addTailwindTheme([
      'extend' => [
        'spacing' => [
          'component' => 'var(--spacing-component, --spacing)',
          'component-xs' => 'calc(var(--spacing-component, --spacing) / 2)',
          'component-sm' => 'calc(var(--spacing-component, --spacing) / 1.5)',
          'component-lg' => 'calc(var(--spacing-component, --spacing) * 1.5)',
          'component-xl' => 'calc(var(--spacing-component, --spacing) * 2)',
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      NeoBuildEvent::EVENT_NAME => 'onBuild',
    ];
  }

}
