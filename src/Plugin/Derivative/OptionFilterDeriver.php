<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Filter\ComponentFilterOptionsPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives an Options component filter per declared option set.
 *
 * One derivative ("options:<set id>", labelled "Options: <title>") is created
 * for every set discovered from *.neo_component_filter_options.yml files.
 */
class OptionFilterDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs an OptionFilterDeriver object.
   */
  public function __construct(
    private readonly ComponentFilterOptionsPluginManager $filterOptions,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('plugin.manager.neo_component_filter_options')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    if (!$this->derivatives) {
      foreach ($this->filterOptions->getDefinitions() as $id => $definition) {
        $this->derivatives[$id] = [
          'label' => $this->t('Options: @title', ['@title' => $definition['title']]),
          'options' => $definition['options'],
          'provider' => $definition['provider'],
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

}
