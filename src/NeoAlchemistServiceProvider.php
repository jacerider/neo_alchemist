<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Modifies the language manager service.
 */
class NeoAlchemistServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('neo_alchemist.media_library.opener', MediaLibraryPropOpener::class)
        ->addTag('media_library.opener');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Overrides plugin.manager.sdc class to add type conversion.
    if ($container->hasDefinition('plugin.manager.sdc')) {
      $definition = $container->getDefinition('plugin.manager.sdc');
      $definition->setClass('Drupal\neo_alchemist\ComponentPluginManager');
      $definition->addArgument(new Reference('plugin.manager.neo_component_prop_def'));
    }
  }

}
