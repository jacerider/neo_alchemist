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
    $config = $event->getConfig();
    $docRoot = $event->getDocRoot();
    $scopedExtensions = $event->getScopedExtensions();
    foreach ($scopedExtensions as $scope => $extensions) {
      if ($scope !== 'front') {
        // Components are only used on the front end.
        continue;
      }
      foreach ($extensions as $id => $extension) {
        $path = $extension->getPath();
        if (file_exists($path . '/' . $id . '.neo_component_prop_defs.yml')) {
          $config['scopes'][$scope]['tailwind']['content'][] = $docRoot . $path . '/' . $id . '.neo_component_prop_defs.yml';
        }
        if (is_dir($path . '/components')) {
          $config['tailwind']['content'][] = $docRoot . $path . '/components/**/*.{yml,twig}';
        }
      }
    }
    $event->setConfig($config);
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
