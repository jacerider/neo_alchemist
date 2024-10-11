<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

class NeoAlchemistServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('neo_alchemist.media_library.opener', MediaLibraryXbPropOpener::class)
        ->addTag('media_library.opener');
    }
  }

}
