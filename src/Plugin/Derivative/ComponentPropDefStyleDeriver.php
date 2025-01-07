<?php

namespace Drupal\neo_alchemist\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\neo_alchemist\ComponentStylePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves block plugin definitions for all toolbar region items.
 */
final class ComponentPropDefStyleDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs a EntityIcon object.
   */
  public function __construct(
    private readonly ComponentStylePluginManager $styleManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('plugin.manager.neo_component_style')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    ksm($this->styleManager->getDefinitions(), $this->styleManager->createInstance('padding')->getOptions());
    return [];
    return [
      $this->styleManager->getPropDef() + $base_plugin_definition,
    ];
  }

}
